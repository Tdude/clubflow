# ClubFlow — Användarguide för Administratörer

*En enkel guide för hur bokningssystemet fungerar.*

---

## Tre sätt att sälja

ClubFlow har tre typer av "produkter" som du kan sälja:

### 1. 📅 Enskilda klasser (Kalender-läge)

**Vad det är:** En vanlig klass som syns i kalendern.

**Exempel:** "Pole 1 — Måndag 18:00"

**Så här fungerar det:**
- Kunden ser klassen i kalendern
- Klickar och bokar direkt
- Kan välja medlem/icke-medlem om du har olika priser
- Får en bekräftelsekod

**När du skapar:** Välj läge "Kalender" (standard)

---

### 2. 🎟️ Klippkort (Paket-läge)

**Vad det är:** Kunden köper flera klasser på en gång men bokar dem en i taget.

**Exempel:** "10-kort Pole" — kunden köper 10 tillfällen och bokar sedan varje klass separat när det passar.

**Så här fungerar det:**
1. Kunden köper klippkortet (en engångsbetalning)
2. Får en **klippkortskod** (t.ex. `POLE-A7B3C9`)
3. När kunden vill gå på en klass, bokar de i kalendern och anger sin kod
4. Systemet drar av ett klipp från kortet

**Viktigt för kunden:**
- Spara koden! (Den sparas också i en cookie för bekvämlighet)
- Koden fungerar tills alla klipp är använda

**När du skapar:** 
- Välj läge "Paket"
- Länka till de klasser som klippkortet gäller för

---

### 3. 🏢 Produkter/Grupp-event (Produkt-läge)

**Vad det är:** En engångsbokning där en person bokar för en hel grupp.

**Exempel:** "Företagsevent — Pole för nybörjare" — ett företag bokar och betalar ett pris för hela gruppen.

**Så här fungerar det:**
- Visas INTE i kalendern (du lägger den på en egen sida)
- En person fyller i bokningen
- Priset gäller för hela gruppen

**När du skapar:**
- Välj läge "Produkt"
- Lägg shortcoden på en egen sida: `[club_booking id="123"]`

---

## Shortcodes — snabbreferens

### Visa kalendern

[club_calendar]

### Visa bokning för ett specifikt event

[club_booking id="123"]
*Ersätt 123 med eventets ID (hittas i URL:en när du redigerar eventet)*

### Popup-knapp istället för formulär
[club_booking id="123" popup="true"]

### Med egen etikett
[club_booking id="123" label="Pole 1 — Nybörjare"]

---

## Prissättning

Du kan ange två priser per event:

| Fält | Beskrivning |
|------|-------------|
| Pris | Ordinarie pris (visas för alla) |
| Medlemspris | Rabatterat pris (kunden väljer själv) |

Om du bara anger ett pris visas inget val.

---

## Vanliga frågor

### Hur ser jag alla bokningar?
Gå till **ClubFlow → Alla Bokningar** i WP-admin.

### Hur ändrar jag färg på en kategori i kalendern?
Gå till **ClubFlow → Eventkategorier** och redigera kategorin. Där kan du välja färg. Om ingen färg visas i kalendern har du glömt välja datum. Vitt betyder att kategori inte valts.

### Varför syns inte mitt event i kalendern?
Kontrollera att:
1. Läget är satt till "Kalender" (inte "Produkt" eller "Paket")
2. Eventet är publicerat
3. Startdatum är rätt

### Hur lägger jag till en betalmetod?
Gå till **Inställningar → ClubFlow** och konfigurera Stripe, Swish eller Klarna. Alla behöver någon firmatecknare i klubben som knyter betalningen till ett konto. Swish och klarna behöver handpåläggning i kod för att funka. Det är bara Stripe som är kollat!

---

*Frågor? Kontakta support hello@idunworks.com eller läs den tekniska dokumentationen i README.md*
