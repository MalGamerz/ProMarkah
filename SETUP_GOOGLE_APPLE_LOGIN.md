# Setting up Google / Apple login for judges

This only affects judges. Admin/PIC/recorder logins are unchanged. A judge
signing in with Google or Apple still needs a **matching email address on
their judge record** — PIC adds this once in Pengurusan Juri, then the judge
can sign in with that Google/Apple account from then on. The judge_id + PIN
login still works exactly as before, side by side with this.

## 1. Deploy the new files

Everything below is already written and wired up — nothing works until you
add real credentials to `oauth_config.php`. On deploy, make sure these are
uploaded along with the rest of the site:

- `oauth_config.php`
- `oauth_helpers.php`
- `oauth_google_callback.php`
- `oauth_apple_start.php`
- `oauth_apple_callback.php`
- **`vendor/`** — the whole folder (installed via Composer, contains the JWT
  verification library). This is new to the project; there was no
  `composer.json`/`vendor/` before this feature.
- `composer.json` / `composer.lock`

## 2. Google — get a Client ID (~5 minutes, free)

1. Go to [Google Cloud Console](https://console.cloud.google.com/) → create a
   project (or use an existing one).
2. **APIs & Services → OAuth consent screen** — set it up (External user
   type is fine), app name "ProMarkah", your support email.
3. **APIs & Services → Credentials → Create Credentials → OAuth client ID**.
   - Application type: **Web application**
   - Authorized JavaScript origins: `https://www.prosilat.net` (and
     `https://prosilat.net` if both are used)
   - Authorized redirect URIs: not required for this flow (it uses Google's
     ID-token sign-in, not a redirect-based exchange)
4. Copy the **Client ID** (looks like `1234567890-abc...apps.googleusercontent.com`).
5. Paste it into `oauth_config.php`:
   ```php
   define('GOOGLE_CLIENT_ID', '1234567890-abc...apps.googleusercontent.com');
   ```

That's it for Google — no Client Secret needed, no private key.

## 3. Apple — get a Services ID (~15 minutes, requires paid Apple Developer account)

Apple Sign In requires an active [Apple Developer Program](https://developer.apple.com/programs/)
membership ($99/year), and your domain must be verifiable by Apple.

1. **Certificates, Identifiers & Profiles → Identifiers → +** → choose
   **Services IDs** → Continue.
2. Description: "ProMarkah Judge Login". Identifier: something like
   `net.prosilat.signin` (reverse-domain style, doesn't have to match a real
   subdomain).
3. After creating it, open it again and enable **Sign in with Apple**, then
   click **Configure**:
   - Primary App ID: you'll need an App ID registered first if you don't have
     one — any existing one for this domain works, or create a minimal one.
   - Domains and Subdomains: `www.prosilat.net` (and/or `prosilat.net`)
   - Return URLs: `https://www.prosilat.net/oauth_apple_callback.php`
4. Save. Apple will verify domain ownership — follow their on-screen
   instructions (usually a file you upload to `/.well-known/`).
5. Copy the **Services ID identifier** you created in step 2 (e.g.
   `net.prosilat.signin`) and paste it into `oauth_config.php`:
   ```php
   define('APPLE_CLIENT_ID', 'net.prosilat.signin');
   ```

No private key, Team ID, or Key ID is needed for this flow — the app only
verifies who signed in (identity), not ongoing API access, so it skips the
authorization-code token exchange entirely.

## 4. Link judges to their email

PIC → Pengurusan Juri → add or edit a judge → fill in the **E-mel** field
with the exact email address the judge will use to sign in with Google/Apple.
Must be the real email on their Google/Apple account, since it's matched by
the **verified** email the provider returns — not something the judge can
type in themselves.

## 5. Test it

Visit `login.php`. If `GOOGLE_CLIENT_ID` is filled in, a Google button
appears under a "Log Masuk Juri Pantas" divider on the login page,
automatically — no other config needed. Same for Apple once
`APPLE_CLIENT_ID` is filled in.

If a judge signs in with an email that isn't linked to any judge record,
they'll see: *"E-mel ini tidak dikaitkan dengan mana-mana akaun juri..."* —
that's expected until PIC adds their email.
