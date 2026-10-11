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
Vanaf 1.3.0 meldt WordPress nieuwe publieke GitHub-releases via de normale pluginupdates. De eerste installatie van 1.3.0 gebeurt handmatig. Automatisch installeren is optioneel in de WordPress-pluginlijst. De updater gebruikt alleen het releasebestand `rex-downloads.zip`, niet de GitHub-broncodearchieven. Geen API-token nodig.

## Nieuwe versie publiceren
1. Verhoog `Version` én het versienummer bij `Rex_Downloads_GitHub_Updater` in `rex-downloads/rex-downloads.php`.
2. Werk `RELEASE_NOTES.md` en `LEESMIJ.txt` bij.
3. Commit naar `main`. GitHub Actions controleert PHP, test de updater, maakt de ZIP en publiceert de nieuwe release.

Bestaande releases worden nooit overschreven. Bij een mislukte run kan de workflow via Actions opnieuw worden gestart. WordPress cachet succesvolle releasechecks één uur en fouten vijf minuten; **Nu controleren** wist deze cache.

Lokaal controleren: `php tests/updater.php` en `python3 scripts/build.py`.

Vereist: WordPress 6.0+, PHP 7.4+. WordPress-server moet GitHub API en release-downloads kunnen bereiken.

Categorieën kunnen ook worden bewerkt en verwijderd. Bij verwijderen blijven downloads bestaan en worden subcategorieën één niveau hoger geplaatst. Categorieën kunnen niet onder zichzelf of eigen subcategorieën worden geplaatst.

Vanaf 1.3.1 toont [rex_downloads] alle categorieën en subcategorieën als uitklapbare mappen, met compacte bestandsregels. Alleen bestanden waarvoor de gebruiker toegang heeft zijn zichtbaar.
