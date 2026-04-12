# Apple Shortcut – Hydrant Hose Calculator

Quickly get the number of hose sections needed to reach the nearest fire hydrant from your current location.
There is a Demo Shortcut already created. 

[Apple Shortcut](https://www.icloud.com/shortcuts/5c7dc85fec134bc4a9c61bb260e1c4a8)

---

## Deployment

Upload the `api/` folder to your Hostpoint webspace. The API will be available at:

```
https://feuerwehr.julian-bruegger.ch/api/hoses?lat=47.04&lng=8.30
```

No server management required — PHP runs serverlessly on Hostpoint's infrastructure.

---

## API Reference

```
GET https://feuerwehr.julian-bruegger.ch/api/hoses
```

| Parameter | Required | Default | Description |
|---|---|---|---|
| `lat` | ✅ | — | Latitude (WGS84) |
| `lng` | ✅ | — | Longitude (WGS84) |
| `hose_length` | ❌ | `20` | Length per hose section in metres |
| `radius` | ❌ | `1500` | Search radius for hydrants in metres |

### Example request
```
https://feuerwehr.julian-bruegger.ch/api/hoses?lat=47.0409&lng=8.3005&hose_length=20
```

### Example response
```json
{
  "hoses": 5,
  "hose_length_m": 20,
  "distance_m": 420,
  "distance_type": "road",
  "hydrant": {
    "lat": 47.039,
    "lng": 8.301,
    "type": "underground",
    "air_distance_m": 310
  },
  "summary": "5 Schläuche à 20 m (420 m Fahrstrecke)"
}
```

`distance_type` is `"road"` when OSRM routing succeeded, `"air"` when only straight-line distance was available.

---

## Shortcut Setup (Step by Step)

### 1. Create a new Shortcut
Open the **Shortcuts** app → tap **+** → name it e.g. `Hydrant Schläuche`.

### 2. Get current location
Add action: **Get Current Location**
- This provides your GPS coordinates.

### 3. Build the URL
Add action: **Text**
- Content:
  ```
  https://feuerwehr.julian-bruegger.ch/api/hoses?lat=[Latitude]&lng=[Longitude]&hose_length=20
  ```
- Replace `feuerwehr.julian-bruegger.ch` with your actual Hostpoint domain.
- Tap `[Latitude]` and `[Longitude]` → insert **Current Location → Latitude / Longitude** variables from step 2.

### 4. Fetch the API
Add action: **Get Contents of URL**
- URL: select the **Text** variable from step 3
- Method: `GET`

### 5. Parse the result
Add action: **Get Dictionary from Input**
- Input: result of step 4

Add action: **Get Dictionary Value**
- Dictionary: result of step 5
- Key: `summary`

### 6. Show the result
Add action: **Show Result** (or **Speak Text** for eyes-free use)
- Input: value from step 6

---

## Result Examples

| Scenario | Output |
|---|---|
| Hydrant 100 m away | `1 Schläuche à 20 m (85 m Fahrstrecke)` |
| Hydrant across town | `8 Schläuche à 20 m (720 m Fahrstrecke)` |
| No hydrant found | Error message with radius hint |

---

## Tips

- **Add to Home Screen**: Long-press the shortcut → Add to Home Screen for one-tap access.
- **Widget**: Add the Shortcuts widget to your lock screen and pin this shortcut.
- **Custom hose length**: Change `hose_length=20` to match your equipment (e.g. `25` or `50`).
- **Larger search area**: Add `&radius=3000` for rural/remote locations.
- **Voice trigger**: "Hey Siri, Hydrant Schläuche" works if the shortcut is saved.

---

## Troubleshooting

- **404**: Make sure `api/hoses.php` and `api/.htaccess` were uploaded correctly.
- **500**: Check that cURL is enabled on your Hostpoint plan (it is by default on all plans).
- **No hydrants found**: Try increasing `&radius=3000`.
- **Slow response**: Overpass API can be slow under load — the script retries on mirrors automatically.
