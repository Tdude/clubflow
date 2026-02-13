# ClubFlow - Användarguide

ClubFlow är ett boknings- och kalenderplugin för WordPress, byggt för föreningar och klubbar.

---

## Funktioner

### 📅 Kalender & Evenemang

- **Fullständig kalendervy** med månadsvy (desktop) och 2-veckorslista (mobil)
- **Kategorier med färgkodning** — varje kategori kan ha egen färg
- **Evenemangsdetaljer** — datum, tid, plats, beskrivning, utvald bild
- **Popup-modal** — klicka på ett evenemang för att se detaljer och boka
- **Shortcode:** `[club_calendar]`

### 🎟️ Bokningssystem

- **Enkel bokning** — namn, e-post, telefon (valfritt)
- **Bekräftelsekod** genereras automatiskt
- **Begränsat antal platser** — visa "X platser kvar" eller "Fullbokat"
- **Dubblettskydd** — samma e-post kan inte boka samma evenemang två gånger

### 💰 Prissättning

- **Standardpris** — visas för alla
- **Medlemspris** (valfritt) — om både pris och medlemspris anges, får besökaren välja:
  - "Jag är: ○ Medlem (150 kr) ○ Icke-medlem (200 kr)"
- Priset sparas på varje bokning

### 💳 Betalning (valfritt)

Stöd för flera betalmetoder:

- **Manuell** — "Betala på plats"
- **Swish** — QR-kod och Swishnummer visas
- **Klarna** — Checkout-integration
- **Stripe** — Kortbetalning via Stripe Checkout

Betalningsstatus visas i admin och kan bekräftas manuellt.

### 📧 E-postbekräftelse

- **Mailchimp-integration** (valfritt) — skickar bokningsbekräftelse via Mailchimp
- Inkluderar: evenemang, datum, plats, bekräftelsekod

---

## Shortcodes

### Kalender
```
[club_calendar]
[club_calendar category="yoga"]
[club_calendar view="listRange" list_months="2"]
```

**Attribut:**
- `category` — filtrera på kategori-slug
- `view` — `dayGridMonth` (standard) eller `listRange`
- `list_months` — antal månader i listvy (1-12)

### Evenemangslista
```
[club_events_list]
[club_events_list limit="5" category="dans"]
```

### Bokningswidget (fristående)
```
[club_booking id="123"]
```
Visar bokningsformulär för ett specifikt evenemang (t.ex. på en produktsida).

---

## Evenemangslägen

Varje evenemang har ett **läge**:

| Läge | Beskrivning |
|------|-------------|
| **Kalender** | Visas i kalendern. Standardläge. |
| **Produkt** | Visas INTE i kalendern. Används för fristående bokningsprodukter. |
| **Paket** | Visas INTE i kalendern. Kan länka till flera andra evenemang. |

---

## Admin

### Skapa evenemang

1. Gå till **Evenemang → Lägg till**
2. Fyll i titel och beskrivning
3. Ställ in **Datum & tid** (start, slut, heldag)
4. Ange **Plats** (valfritt)
5. Välj **Kategori** (med färg)
6. Under **Bokning**:
   - ✅ Aktivera bokning
   - Ange max platser (0 = obegränsat)
   - Ange pris och/eller medlemspris
7. Publicera

### Hantera bokningar

- **Evenemang → [evenemang] → Bokningar** — se alla bokningar för ett evenemang
- **Bokningar** — lista alla bokningar
- Varje bokning visar: namn, e-post, telefon, medlemstyp, pris, bekräftelsekod, status

### Betalningsinställningar

Under **Evenemang → Inställningar**:

- Aktivera betalning
- Välj metod (Manuell / Swish / Klarna / Stripe)
- Konfigurera nycklar/certifikat

---

## Mobil

Kalendern anpassar sig automatiskt för mobiler:

- **Vy:** 2-veckorslista istället för månadsgrid
- **Titel:** Bara månad (utan år)
- **Navigation:** Förenklad (prev/next + idag)
- **Popup:** Fullskärm med scrollning

---

## Språk

Pluginet är helt översatt till svenska. Kalendern visar svenska månadsnamn, veckodagar etc.

### Filer
```
languages/
├── clubflow.pot          (mall)
├── clubflow-sv_SE.po     (svensk översättning)
└── clubflow-sv_SE.mo     (kompilerad)
```

### Ändra översättning

1. Redigera `clubflow-sv_SE.po` (med Poedit eller texteditor)
2. Kompilera: `msgfmt clubflow-sv_SE.po -o clubflow-sv_SE.mo`
3. Ladda upp `.mo`-filen

---

## Installation

1. Ladda upp `clubflow/` till `/wp-content/plugins/`
2. Aktivera pluginet
3. Ställ in WordPress på svenska (Inställningar → Allmänt)
4. Skapa evenemang och lägg in `[club_calendar]` på en sida

---

## Felsökning

| Problem | Lösning |
|---------|---------|
| Översättning visas inte | Kontrollera att WordPress är inställt på Svenska |
| Evenemang syns inte | Kontrollera att evenemangsläget är "Kalender" |
| Bokning fungerar inte | Kontrollera att "Aktivera bokning" är ikryssad |
| Betalning misslyckas | Kontrollera API-nycklar under Inställningar |

---

**Version:** 0.3.x  
**Utvecklare:** Tibor Berki  
**Licens:** GPL v2+
