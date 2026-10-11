WP Dares Downloads 3.0.0

- Bestandsvolgorde verslepen en met knoppen aanpassen, met een eigen volgorde per categorie.
- Bulkbeheer: categorieën, rollen en publicatieplanning vervangen of bestanden naar de prullenbak verplaatsen.
- Eigen prullenbak met herstellen en definitief verwijderen; geen automatische verwijdering door WordPress.
- Echte oude bestandskopieën bewaren, downloaden en terugzetten. Ook de vervangen huidige versie blijft behouden.
- Toegang tot een categorie aanvragen; beheerders kunnen goedkeuren, afwijzen en intrekken.
- Bestanden en categorieën volgen met e-mailupdates; persoonlijke abonnementen op de downloadpagina beheren.
- Publiceren vanaf een datum en toegang beëindigen op een einddatum, in de WordPress-tijdzone.
- Bestandscontrole voor aanwezigheid, grootte en SHA-256, inclusief oude versies.
- PDF bekijken in een venster op de downloadpagina met een downloadknop en apart openen als alternatief.
- Dashboard met populaire en recent bijgewerkte downloads, open aanvragen en controleresultaten.
- Export/import neemt oude bestandskopieën en publicatieplanning mee.

Alle bestaande functies blijven beschikbaar: meerdere rollen, categorieën/subcategorieën, mediabibliotheek, bestandsnamen overnemen, categorievolgorde, zoeken, filters, favorieten, statistieken, logboek, vormgeving en GitHub-updates.

Oude bestanden worden vanaf deze update bewaard bij vervanging. Eerder verwijderde versies kunnen niet worden teruggehaald. Goedkeuring geeft toegang tot de huidige bestanden in de categorie en subcategorieën; nieuwe bestanden krijgen niet automatisch een afzonderlijke goedkeuring. Overlappende goedkeuringen blijven geldig bij intrekken.

E-mailupdates gebruiken WordPress wp_mail en WP-Cron. Stel een echte cronjob en betrouwbare mailbezorging in voor tijdige meldingen. Abonnees zonder actuele toegang ontvangen geen melding. Publicatiedatums worden bij iedere download gecontroleerd en zijn niet afhankelijk van cron.

Prullenbak en oude versies gebruiken schijfruimte totdat een beheerder definitief verwijdert. Een eerste controle van een ouder bestand zonder checksum maakt een referentie en kan eerdere schade niet aantonen.

Export/import vereist PHP ZIP. Maximaal 500 downloads, 5000 oude bestandsversies en 512 MB uitgepakt, binnen de uploadlimiet. Gebruikersaccounts, favorieten, abonnementen, toegangsaanvragen/toestemmingen, prullenbak en logs worden niet geëxporteerd. Bestaande export-ID's worden overgeslagen.
