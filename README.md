# Villa E25 — e25.at

The website for Villa E25, Stuhleckblick 25, Steinhaus am Semmering.

It is a **private house, not a rental.** The site says so plainly and carries no
contact details, no prices and no booking form — that is deliberate, please keep
it that way in any future edit.

## What is in here

| File | What it does |
|---|---|
| `index.html` | The entire website — markup, styles and scripts in one file. |
| `ics.php` | Reads the Google Calendar on the server so the availability calendar can show it. |
| `img/` | Photographs, built from the 42-megapixel originals. |
| `calendar-check.html` | Diagnostic page. Open it on the live site if the calendar misbehaves. |
| `.github/workflows/deploy.yml` | Pushes to `main` publish to e25.at over FTP. |

## Editing

Everything lives in `index.html`. The pieces you are most likely to touch sit
together at the top of the `<script>` block at the very bottom of the file:

```js
var E25_BOOKED       = [ ... ];   // dates blocked by hand, as a fallback
var E25_CALENDAR_ID  = "...";     // the public Google Calendar
var E25_ICS_PROXY    = "ics.php"; // leave as is
```

Text appears twice, once per language, as sibling elements:

```html
<p lang="en">In English.</p>
<p lang="sk">Po slovensky.</p>
```

Change both, or the two versions drift apart.

## The availability calendar

The page draws its own calendar rather than embedding Google's, because a
cross-origin iframe cannot be styled to match the site. It looks for dates in
this order and uses the first that answers:

1. **`ics.php` on this server** — the one that works.
2. Google Calendar API from the browser, if `E25_CALENDAR_KEY` is set. Normally leave it empty.
3. Google's public `.ics` read directly — **permanently blocked**: that endpoint sends no CORS header. Kept only in case Google ever changes.
4. `E25_BOOKED`, typed by hand. Drawn immediately on load, so the section is never empty or broken.

Add `?debug` to any page address — `e25.at/?debug` — and a panel under the
calendar reports which route won and why the others did not.

### Making it update within seconds

By default `ics.php` reads Google's public feed, which Google itself refreshes
only every few minutes, so a new booking takes 15–30 minutes to appear. With an
API key it calls the Calendar API instead and the delay disappears.

1. In [console.cloud.google.com](https://console.cloud.google.com): create a project.
2. APIs & Services → Library → enable **Google Calendar API**.
3. Credentials → Create credentials → **API key**. Restrict it to the Calendar
   API. Leave *Application restrictions* set to None — the key is used from the
   server, not from browsers, so a website restriction would break it.
4. Create a file next to `ics.php` called **`ics-key.php`** containing one line:

   ```php
   <?php $API_KEY = 'AIza...';
   ```

   That filename is in `.gitignore`, so the key can never be committed, and the
   deploy workflow skips it. Upload it once by FTP and leave it there.
5. Check <https://www.e25.at/ics.php?ping>. It should say `mode: api`.

`?ping` is the first thing to look at whenever the calendar seems wrong — it
prints the mode, the cache setting, the event count, or the exact failure.

### School holidays

The calendar also marks Slovak public holidays and school breaks, and tints the
week when Steiermark schools take their ski holidays and the local slopes fill
up. These are computed, not stored, so they keep working indefinitely.

The Austrian dates are exact — the Schulzeitgesetz fixes Semesterferien to the
third Monday of February for Steiermark. The Slovak school dates are official
only through school year 2027/28; later years are marked `~` and follow the
pattern the Ministry has used every year since 2019 (spring break starts the
third Monday of February, the three regional groups rotating on a three-year
cycle). If the Ministry publishes further years, update `SK_PUBLISHED_TO` in
`index.html` and check the computed dates still match.

## Deploying

Push to `main` and the GitHub Action uploads the site over FTP. It needs three
repository secrets — Settings → Secrets and variables → Actions:

| Secret | Value |
|---|---|
| `FTP_SERVER` | `e25.at` |
| `FTP_USERNAME` | `e25.at` |
| `FTP_PASSWORD` | the FTP password |

If the host serves the site from a subfolder of the FTP account, set
`server-dir` in `.github/workflows/deploy.yml` to match — often `web/`.

To upload by hand instead:

```bash
P='your-ftp-password'
curl -u "e25.at:$P" -T index.html ftp://e25.at/
curl -u "e25.at:$P" -T ics.php   ftp://e25.at/
for f in img/*.jpg; do
  curl -u "e25.at:$P" --ftp-create-dirs -T "$f" "ftp://e25.at/img/$(basename "$f")"
done
```

## Still to do

- Two photographs of the ski resorts. Drop `stuhleck.jpg` and `semmering.jpg`
  into `img/` and the row in the *Skiing* section appears by itself — it stays
  hidden while they are missing.
