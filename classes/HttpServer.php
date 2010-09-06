<?php
/**
 * Een HTTP Server in PHP
 * 
 * Emuleert het gedrag van ApacheHTTPd door o.a de $_SERVER & $_GET variabele aan te passen.
 */
class HttpServer extends Website {

	public
		$document, // Document object
		$tickInterval; // Aantal seconden tussen de tick()s

	private 
		$port,
		$_server, // De waardes die elke request als bron gebruikt wordt voor de $_SERVER variabele
		$logStream, // Filepointer naar de logfile. Zie log()
		$lastTick; // timestamp van de laatste aanroep van tick() via interrupt()

	function __construct($port) {
		define('WEBROOT', '/');
		define('WEBPATH', '/');
		$this->depth = 0;
		parent::__construct();
		$this->publicMethods = array_diff($this->publicMethods, array('run', 'tick', 'parseRequest', 'sendResponse', 'handleRequest')); // Een aantal functies *niet* public maken
		$this->port = $port;
		$this->_server = array(
			'SERVER_SOFTWARE' => 'SledgeHammer/1.2 HttpServer',
			'SERVER_PROTOCOL' => 'HTTP/1.1',
			'SERVER_PORT' => $this->port,
		);
		$this->handle_filenames_without_extension = true;
	}	

	/**
	 * Start de server
	 */
	function run() {
		// De serverSocket openen en luisteren naar binnenkomende requests.
		$serverSocket = stream_socket_server('tcp://0.0.0.0:'.$this->port, $err_nr, $error_message);
		if (!$serverSocket) {
			error('['.$err_nr.'] '.$error_message);
		}
		$this->log($this->_server['SERVER_SOFTWARE'].' started');
		$clientSocket = null;
		while (true) { // Main loop
			if ($clientSocket === null || feof($clientSocket)) { // Is er geen verbinding met een client?
				$clientSocket = $this->accept($serverSocket); // Binnenkomende verbinding
				$lastConnection = microtime(true);
				if (!$clientSocket) {
					break;
				}
			} else { // Er is nog een openstaande verbinding.
				// Bepaal de timeout van de stream_select 
				$timeout = isset($_SERVER['HTTP_KEEP_ALIVE']) ? $_SERVER['HTTP_KEEP_ALIVE'] : 60; // Timeout van een Idle connectie.
				if ($this->tickInterval > 0 && $this->tickInterval < $timeout) {
					$selectTimeout = $this->tickInterval; // Er is een tickIterval binnen de timeout.
				} else {
					$selectTimeout = $timeout; 
				}
				$read = array($serverSocket, $clientSocket);
				$null = null;
				if (stream_select($read, $null, $null, $selectTimeout) === false) {
					break; // Error opgetreden. Stop server?
				}
				if (count($read) == 0) {
					$this->interrupt(); // Voer mogelijk een tick() uit.
					// @todo Sluit de idle client verbinding als de timeout is verstreken
					continue; // Opnieuw
				}
				$who = 'server';
				foreach ($read as $socket) {
					if ($socket === $clientSocket) {
						$who = 'client';
						// $this->log('Same connection');
					}
				}
				if ($who == 'server') { // Heeft de client *geen* request klaar staan?
					fclose($clientSocket); // De "idle" connectie sluiten
					$clientSocket = $this->accept($serverSocket); // en de 
				}
			}
			if ($clientSocket == false) {
				notice('No client connection', 'accept() failed?');
				break; // Stop server?
			}
			ob_start();
			$httpStatus = $this->parseRequest($clientSocket);
			if ($httpStatus == 'DISCONNECTED') {
				continue;
			}
			if ($httpStatus == 200) {
				$component = $this->execute();
				$isWrapable = true;
				if (method_exists($component, 'isWrapable')) {
					$isWrapable =  $component->isWrapable();
				}
				if ($isWrapable) {
					$this->document->component = $component;
				} else {
					$this->document = $component;
				}
			} else {
				$codes = array(
					400 => array('text' => 'Bad Request', 'description' => 'Server begreep de aanvraag niet'),
					501 => array('text' => 'Not Implemented', 'description' => 'Dit wordt niet door de server ondersteund'),
				);
				$error = $codes[$httpStatus];
				$this->document->headers[] = $_SERVER['SERVER_PROTOCOL'].' '.$httpStatus.' '.$error['text'];
				$this->document->title = $httpStatus.' - '.$error['text'];
				$this->document->component = new MessageBox('warning.png', $error['text'], $error['description']);
			}
			$this->sendResponse($clientSocket, ob_get_clean());
			$this->cleanupResponse();
			if ($_SERVER['REQUEST_PROTOCOL'] == 'HTTP/1.0' || strtolower(value($_SERVER['HTTP_CONNECTION'])) == 'close') { // Moet de connectie worden gesloten?
				fclose($clientSocket); // close connection (send EOF)
				$clientSocket = null;
			}
		}
		$this->log('HttpServer stopped');
		fclose($serverSocket);
	}

	/**
	 * Deze functie zal ongeveer elke X seconden ($this->tickInterval) worden aangeroep
	 */
	function tick() {
		// Virtual
		notice(get_class($this).'->tick() not implemented');
	}

	/**
	 * Genereer een Component/Document om te gebruiken in de response.
	 * Net als Website/VirtualFolder
	 * @return Component|Document
	 */
	function execute() {
		$webpath = URL::info('path');
		$modulePath = PATH.'sledgehammer';
		$files = array(
			PATH.'application/public'.$webpath,
		);
		if (substr($webpath, -1) != '/') { // Gaat het om een bestand?
			$firstSlashPos = strpos($webpath, '/', 1);
			if ($firstSlashPos) { // Gaat het om een submap?
				$firstSlashPos++;
				$firstFolder = substr($webpath, 0, $firstSlashPos);
				$filepath = substr($webpath, $firstSlashPos);
				$files[] = $modulePath.$firstFolder.'public/'.$filepath; // Dan kan het bestand ook in een module staan
			}

			if ($webpath == '' || substr($webpath, -1) == '/') { // Gaat de request om een map?
				$indexFiles = array();
				foreach ($files as $filename) {
					// Zoek naar index bestanden in de public/ mappen. Ala DirectoryIndex
					foreach(array('index.html', 'index.htm', 'index.php') as $indexFile) {
						$indexFiles[] = $filename.$indexFile;
					}
				}
				$files = $indexFiles;
			}
			foreach($files as $filename) {
               	if (is_file($filename)) {
					// @todo substr($path, -4) != '.php'
					return new FileDocument($filename);
				}
			}
		}
		return VirtualFolder::execute();
	}

	/**
	 * Schrijf een $message naar het log-bestand
	 *
	 * @param string $message
	 * @return void
	 */
	function log($message) {
		if ($this->logStream === null) {
			$this->logStream = fopen(PATH.'tmp/access.log', 'a');
		}
		if (is_resource($this->logStream)) {
			fputs($this->logStream, date('[Y-m-d H:i:s] ').$message."\n");
		}
	}

	/**
	 * Leest de request uit van de client. en Vult $_SERVER, $_GET variabelen.
	 * Als de deze 200 returnt is de request correct en mag er een pagina gegeneerd worden.
	 *
	 * @return enum "DISCONNECTED", 400, 501 of 200
	 */
	protected function parseRequest($socket) {
		$_SERVER = $this->_server;
		$initialLine = fgets($socket);
		if ($initialLine === false) {
			$GLOBALS['VirtualFolder'] = $this;
			// $this->log('Client disconnected');
			return 'DISCONNECTED';
		}
		preg_match('/([A-Z]+) (.+) (HTTP\/1.[01]{1})$/', rtrim($initialLine), $parts);
		if (count($parts) != 4) {
			return 400;
		}
		$_SERVER['REQUEST_METHOD'] = $parts[1];
		if ($_SERVER['REQUEST_METHOD'] != 'GET') {
			return 501; // @todo ook POST en HEAD gaan ondersteunen
		}
		$_SERVER['REQUEST_PROTOCOL'] = $parts[3];
		if ($_SERVER['REQUEST_PROTOCOL'] == 'HTTP/1.1') {
			fputs($socket, "HTTP/1.1 100 Continue\r\n\r\n");
		}
		$uri = $parts[2];
		$_SERVER['REQUEST_URI'] = $uri;
		$pos = strpos($uri, '?');
		if ($pos === false) {
			$_SERVER['QUERY_STRING'] = '';
		} else {
			$_SERVER['QUERY_STRING'] = substr($parts[2], $pos + 1);
			parse_str($_SERVER['QUERY_STRING'], $_GET); // Vul de $_GET variabele met behulp van de QUERY_STRING
		}
		$GLOBALS['ErrorHandler']->html = (bool) value($_GET['debug']);

		// Request headers uitlezen
		while ($line = fgets($socket)) {
			if ($line === "\r\n" || $line === "\n") { // Alle Request headers zijn binnen.
				break;
			}
			// @todo multi-line headers
			$pos = strpos($line, ': ');
			$key = substr($line, 0, $pos);
			$value = substr($line, $pos + 2);
			$requestHeaders[$key] = rtrim($value); 
		}
		if ($line === false) { // read failed
			return 'DISCONNECTED';
		}

		// $_SERVER['HTTP_*'] variabelen instellen
		foreach ($requestHeaders as $name => $value) {
			$_SERVER['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
		}
		if (isset($_SERVER['HTTP_HOST'])) {
			$_SERVER['SERVER_NAME'] = preg_replace('/:[0-9]+$/', '', $_SERVER['HTTP_HOST']); // http_host = servername + port
		} else {
			// if HTTP/1.1 client, geef error
		}
		return 200;
	}

	/**
	 * De gegeneerde objecten en arrays opschonen.
	 * Is nodig voor een correcte werking van de volgende request.
	 *
	 * @return void
	 */
	protected function cleanupResponse() {
		$_GET = array();
		URL::$cached_extract_path = null;
		$GLOBALS['VirtualFolder'] = null;
		$this->document = null;
		$this->initDocument(); // Nieuw document object instellen.
	}

	protected function sendResponse($socket, $responsePrefix = '') {
		// HTTP Headers
		// Van alle headers alleen de laatste waarde versturen.
		$headers = array(
			'HTTP/1.x' => $_SERVER['SERVER_PROTOCOL'].' 200 OK',
			'Date' => 'Date: '.date('r'),
		);
		$httpStatus = 200;
		foreach ($this->document->headers as $index => $header) {
			if (substr($header, 0, 5) == 'HTTP/') { // Is er HTTP status header ingesteld? (404 etc)
				$headers['HTTP/1.x'] = $header; 
				$httpStatus = intval(substr($header, 9, 3));
				continue;
			}
			$name = substr($header,0, strpos($header, ': '));
			$headers[$name] = $header;
		}
		ob_start();
		$this->document->headers = array();
 		$contents = component_to_string($this->document); // Generate contents string
		$contents = $responsePrefix.ob_get_clean().$contents;
		$contentLength = strlen($contents);
		if ($httpStatus == 200) {
			$headers['Content-Length'] = 'Content-Length: '.$contentLength;
		}
		$this->log($_SERVER['REQUEST_METHOD'].' '.$_SERVER['REQUEST_URI'].' '.$httpStatus.' '.($contentLength == 0 ? '-' : $contentLength));
		$eol = "\r\n";
		$response = implode($eol, $headers).$eol.$eol.$contents; // Voeg de headers en de contents samen in 1 response string
		fwrite($socket, $response); // Verstuur de response
	}



	/**
	 * Wachten op een nieuwe request.
	 * Houd rekening met de tickIterval en roept regelmatig de interrupt() functie aan.
	 */
	protected function accept($socket) {
		// $this->log('Connection accept');
		if ($this->tickInterval <= 0) { // Is er geen tickInterval ingesteld
			return stream_socket_accept($socket, -1); // Wacht oneindig op een inkomende connectie
		}
		while(true) {
			$this->interrupt();
			$read = array($socket);
			$null = null;
			$timeout = $this->tickInterval; // @todo calculate timeout based on lastTick
			if (stream_select ($read, $null, $null, $timeout) === false) {
				return false;
			}
			if (count($read) > 0) { // Inkomende connectie?
				return stream_socket_accept($socket); // Accepteer de inkomende connectie
			}
		}	
	}

	/**
	 * Controleerd of de tick() functie aangeroepen moet worden.
	 */
	private function interrupt() {
		if ($this->tickInterval <= 0) { // Is er geen tickInterval ingesteld
			return;
		}
		$now = microtime(true);
		if ($this->lastTick < ($now - $this->tickInterval)) {
			$this->lastTick = $now;
			$this->tick();
		}
	}
}
?>
