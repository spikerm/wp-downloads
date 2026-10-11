# WP Dares Downloads

WordPress-plugin voor downloads met meerdere gebruikersrollen, categorieën en subcategorieën, mediabibliotheekselectie en GitHub-updates.

## Installeren
Download **rex-downloads.zip** bij [Releases](https://github.com/spikerm/wp-downloads/releases/latest). Upload via Plugins → Nieuwe plugin → Plugin uploaden. Kies vervangen bij een bestaande installatie. De pluginmap blijft `rex-downloads` zodat bestaande instellingen behouden blijven.

Plaats `[rex_downloads]` op je downloadpagina.

## Beheer
- **Mijn Downloads**: bestanden van de ingelogde beheerder.
- **Alle Downloads**: alle bestanden.
- **Nieuw bestand**: bestand uit de mediabibliotheek kiezen, categorieën en toegang selecteren.
- **Categorieën**: categorieën en subcategorieën maken door een hoofdcategorie te kiezen.
- **Rollen**: downloadgroepen maken. Meerdere groepen toewijzen via Gebruikers → Bewerken.
- **Updates**: versie bekijken en direct op updates controleren.

Downloads gebruiken een privébestandskopie buiten de webroot. Het originele mediabestand blijft bereikbaar op zijn bestaande URL. Zie `rex-downloads/LEESMIJ.txt` voor opslagconfiguratie.

## Updates
Vanaf 1.3.0 meldt WordPress nieuwe publieke GitHub-releases via de normale pluginupdates. Installeer de huidige release één keer handmatig als je nog een versie zonder updater gebruikt. Automatisch installeren is optioneel in de WordPress-pluginlijst. De updater gebruikt alleen het releasebestand `rex-downloads.zip`, niet de GitHub-broncodearchieven. Geen API-token nodig.

## Nieuwe versie publiceren
1. Verhoog `Version` én het versienummer bij `Rex_Downloads_GitHub_Updater` in `rex-downloads/rex-downloads.php`.
2. Werk `RELEASE_NOTES.md` en `LEESMIJ.txt` bij.
3. Commit naar `main`. GitHub Actions controleert PHP, test de updater, maakt de ZIP en publiceert de nieuwe release.

Bestaande releases worden nooit overschreven. Bij een mislukte run kan de workflow via Actions opnieuw worden gestart. WordPress cachet succesvolle releasechecks één uur en fouten vijf minuten; **Nu controleren** wist deze cache.

Lokaal controleren: `php tests/updater.php` en `python3 scripts/build.py`.

Vereist: WordPress 6.0+, PHP 7.4+. WordPress-server moet GitHub API en release-downloads kunnen bereiken.

Categorieën kunnen ook worden bewerkt en verwijderd. Bij verwijderen blijven downloads bestaan en worden subcategorieën één niveau hoger geplaatst. Categorieën kunnen niet onder zichzelf of eigen subcategorieën worden geplaatst.

Vanaf 1.3.1 toont [rex_downloads] alle categorieën en subcategorieën als uitklapbare mappen, met compacte bestandsregels. Alleen bestanden waarvoor de gebruiker toegang heeft zijn zichtbaar.

## Functies vanaf 2.0.0

De downloadpagina heeft zoeken en filters voor categorie, bestandstype en favorieten. Favorieten worden per WordPress-account bewaard. PDF bekijken opent een beveiligde inline-PDF in een nieuw tabblad; de server controleert login, bestandsrollen en nonce opnieuw.

Bij **Nieuw bestand** kun je maximaal 50 bestanden tegelijk uit de mediabibliotheek selecteren. Elk bestand krijgt zijn eigen bestandsnaam als titel; categorieën, rollen, beschrijving en versie worden gedeeld. Bij bewerken vervang je één bestand.

Bij een download kun je **Versie** en **Wijzigingen** invullen. De laatste 50 versieregistraties worden bewaard. Nieuw/Bijgewerkt-labels zijn standaard 14 dagen zichtbaar. De wijzigingsgeschiedenis bewaart tekst en versienummers, geen oude bestandskopieën.

### Toegang via categorieën
Bestaande downloads blijven hun eigen toegangsrollen gebruiken. Kies per bestand **Rollen van de categorie overnemen** om categorietoegang toe te passen. Een categorie kan eigen rollen hebben of van de bovenliggende categorie overnemen. Een hoofdcategorie zonder eigen regels geeft toegang aan alle ingelogde gebruikers. Een eigen regeling zonder vinkjes is uitsluitend voor beheerders. Bij meerdere toegewezen categorieën geeft één toegestane categorie toegang; een bestand zonder categorie met overerving is uitsluitend voor beheerders.

### Volgorde en vormgeving
Versleep categorieën met ↕ en klik **Volgorde opslaan**. Het verplaatsen gebeurt tussen categorieën van dezelfde hoofdmap; kinderen gaan mee. De Omhoog/Omlaag-knoppen blijven beschikbaar als toetsenbordalternatief.

Via **Vormgeving & instellingen** pas je accentkleur, compactheid, standaard ingeklapte mappen en labelduur aan.

### Statistieken en privacy
**Statistieken** toont downloads en PDF-voorbeelden per bestand en gebruiker. **Downloadlogboek** toont gebeurtenissen met tijdstip. Een gebeurtenis betekent dat de overdracht/opening is gestart, niet dat deze volledig is afgerond. De plugin bewaart geen IP-adressen. Gebruikerskoppeling is uitschakelbaar voor nieuwe gebeurtenissen. Bewaartermijn: standaard 90 dagen, instelbaar van 1 tot 3650 dagen. Dagelijkse opschoning gebruikt WordPress cron; het opslaan van de instellingen ruimt ook direct verlopen gebeurtenissen op. Statistieken gaan over de bewaarde periode en starten vanaf installatie van 2.0.0.

### Export en import
**Export & import** maakt een ZIP met beveiligde bestanden, categorieën, toegang, versiegeschiedenis, downloadrollen en vormgeving. Gebruikers, favorieten en logs worden niet meegenomen. Import voegt toe en slaat downloads met dezelfde export-ID over; bestaande bestanden worden niet overschreven. Vormgeving overnemen is optioneel. Nieuwe downloadrollen krijgen alleen `read`.

PHP ZIP is vereist. Limieten: maximaal 500 bestanden, 512 MB uitgepakt, manifest maximaal 5 MB; de uploadlimiet blijft gelden. Import controleert bestandsgrootte en SHA-256 zonder ZIP-paden uit te pakken. Bij een onverwachte opslagfout blijven reeds geïmporteerde items bestaan en kan dezelfde export opnieuw worden geïmporteerd. Controleer de toegang op de doelsite; onbekende bestaande WordPress-rollen worden niet naar andere rollen vertaald.

### Validatie
CI controleert PHP en JavaScript, updater, categoriehiërarchie en volgorde, toegangsbeleid, exportmanifesten, zoekfilters, favorieten, bulkselectie en slepen. HTTP-tests gebruiken een lokale WordPress-API-fixture voor daadwerkelijke PHP uploads, streaming, PDF/nonce-controle en een export/import-rondgang. Dit vervangt geen compatibiliteitstest op je eigen WordPress-installatie met andere plugins.

## Nieuw in 3.0.0
- **Dashboard**: populariteit binnen de logbewaartermijn, recente wijzigingen en open aanvragen.
- **Bestanden beheren**: slepen of omhoog/omlaag, volgorde per categorie en bulkacties op maximaal 500 geselecteerde bestanden.
- **Prullenbak**: herstellen of definitief wissen inclusief alle bestandsversies. Geen automatische vervaldatum.
- **Bestand bewerken**: oude kopieën terugzetten/downloaden, begin- en einddatum plannen en e-mailmeldingen aanzetten.
- **Toegangsaanvragen**: gebruikers vragen toegang tot een categorie. Goedkeuring geldt voor de huidige bestanden in die categorie en subcategorieën; rollen en publicatiegrenzen blijven gelden.
- **Volgen**: een bestand of categorie volgen; afmelden kan via **Mijn abonnementen**, ook als toegang later vervalt.
- **Bestandscontrole**: huidige en oude bestanden controleren op ontbreken, grootte en checksum.
- **PDF-venster**: beveiligd voorbeeld op dezelfde pagina, inclusief downloadknop.

Oude versies worden vanaf installatie van 3.0.0 bij vervanging bewaard. Zorg voor voldoende privéopslag. E-mail gebruikt `wp_mail` en WP-Cron: configureer betrouwbare mailbezorging en een echte cronjob voor tijdige meldingen. Toegang op begin-/einddatums wordt direct gecontroleerd, onafhankelijk van cron. Export bevat ook oude bestandskopieën en planning; persoonlijke abonnementen, aanvragen, verleende individuele toegang en prullenbak blijven op de oorspronkelijke site.
