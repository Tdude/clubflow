# ClubFlow - Svenska översättningen

## Installation av svenska språket

### Automatisk installation (rekommenderad)

1. Ladda upp alla filer till din WordPress-installation:
   - `clubflow.php` → `/wp-content/plugins/clubflow/`
   - `languages/clubflow-sv_SE.mo` → `/wp-content/plugins/clubflow/languages/`
   - `languages/clubflow-sv_SE.po` → `/wp-content/plugins/clubflow/languages/`

2. Se till att din WordPress-installation är inställd på svenska:
   - Gå till **Inställningar → Allmänt**
   - Välj **Svenska** under "Webbplatsens språk"
   - Spara ändringar

3. Aktivera pluginet - det kommer automatiskt att använda svenska texter!

### Översatta delar

Alla följande delar är översatta till svenska:

#### Admin-gränssnittet
- "Events" → "Evenemang"
- "Add New Event" → "Lägg till evenemang"
- "Edit Event" → "Redigera evenemang"
- "Event Categories" → "Evenemangskategorier"
- "Event Tags" → "Evenemangstaggar"
- "Event Details" → "Evenemangsdetaljer"
- "Start date/time" → "Startdatum/tid"
- "End date/time" → "Slutdatum/tid"
- "All day" → "Heldag"
- "Location" → "Plats"
- "Color" → "Färg"
- "Choose a color for events in this category" → "Välj en färg för evenemang i denna kategori"

#### Frontend
- "Open event page" → "Öppna evenemangssida"

#### Kalendern
Kalendervyn använder redan svenska texter via FullCalendar (konfigurerat i pluginet):
- Månadsnamn, veckodagar, etc. visas på svenska
- "Idag", "Månad", "Veckolista" osv.

## Redigera översättningar

Om du vill ändra någon översättning:

1. Öppna filen `languages/clubflow-sv_SE.po` i en texteditor eller använd ett verktyg som [Poedit](https://poedit.net/)

2. Hitta den text du vill ändra, till exempel:
```
msgid "Event"
msgstr "Evenemang"
```

3. Ändra översättningen (texten efter `msgstr`)

4. Kompilera PO-filen till MO-format:
   - Med Poedit: Spara filen (kompilerar automatiskt)
   - Manuellt: Använd `msgfmt` kommandot:
     ```bash
     msgfmt clubflow-sv_SE.po -o clubflow-sv_SE.mo
     ```
   - Eller använd det medföljande Python-skriptet:
     ```bash
     python3 compile_po.py
     ```

5. Ladda upp den nya `.mo` filen till servern

6. Rensa WordPress-cache om du använder ett cache-plugin

## Lägga till fler språk

För att skapa översättningar för andra språk:

1. Kopiera `clubflow-sv_SE.po` och döp om den till ditt språk, t.ex.:
   - `clubflow-da_DK.po` (Danska)
   - `clubflow-nb_NO.po` (Norska Bokmål)
   - `clubflow-fi.po` (Finska)

2. Översätt alla `msgstr` värden till ditt språk

3. Kompilera till `.mo` format

4. Ladda upp båda filerna till `languages/` mappen

## Filstruktur

```
clubflow/
├── clubflow.php          (huvudfilen)
├── style.css                  (CSS, om du har en)
└── languages/
    ├── clubflow.pot       (mall för översättningar)
    ├── clubflow-sv_SE.po  (svensk översättning - läsbar text)
    ├── clubflow-sv_SE.mo  (svensk översättning - kompilerad)
    └── compile_po.py          (verktyg för att kompilera .po till .mo)
```

## Felsökning

### Översättningen visas inte

1. **Kontrollera WordPress språkinställning**:
   - Gå till Inställningar → Allmänt
   - Kontrollera att "Webbplatsens språk" är inställt på Svenska

2. **Kontrollera filnamn**:
   - Filen måste heta exakt `clubflow-sv_SE.mo` (inte `clubflow-sv.mo`)

3. **Kontrollera sökväg**:
   - Filen måste ligga i `/wp-content/plugins/clubflow/languages/`

4. **Kontrollera filrättigheter**:
   - `.mo` filen måste vara läsbar av webbservern (chmod 644)

5. **Rensa cache**:
   - Rensa WordPress object cache
   - Rensa eventuella cache-plugins
   - Prova i inkognitoläge

6. **Aktivera debugging**:
   I `wp-config.php`, lägg till:
   ```php
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   ```
   Kontrollera sedan `/wp-content/debug.log` för felmeddelanden

### Vissa texter är fortfarande på engelska

Kalendervyn (FullCalendar) är redan konfigurerad för svenska i plugin-koden. Om du ser engelska texter i kalendern kan det bero på:
- Cache-problem (rensa cache)
- JavaScript-fel (öppna webbläsarens konsol för att se felmeddelanden)

## Support

För frågor eller problem, öppna ett issue på GitHub eller kontakta plugin-utvecklaren.
