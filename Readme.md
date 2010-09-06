SledgeHammer HTTP module
-------------------------

Een HTTP/1.1 implementatie in PHP.


Hiermee kun je een REST interface bouwen waarbij na de request het PHP proces blijft doorgaan.

Bevat een **Scheduler** die ervoor zorgt dat er niet meer dan X processen tegelijkertijd draaien.
De andere taken komen in een queue, zodra een proces voltooid is, wordt deze gestart.

Beperkingen:

* Single Threaded (1 php proces dat de requests afhandeld)
* No failure recovery (1 php fout en de server stopt)

Voordelen:

* Een proces start 'direct' met verwerken (geen cron vertraging)
* Task queues, (Zodat je niet meer processen start dan cpu's)
