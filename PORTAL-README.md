# The Cocoon Collective™ — parent sign-up portal

A standalone site. Not a page on cocoon-collective.org, not a WordPress plugin, not part of
the game servers. Its own property, its own database, its own blast radius.

Built to the spec in `F:\work_with_Claude\PARENT-SIGNUP-AND-PROVISION-SPEC.md`, 15 August 2026.

---

## The whole thing in one diagram

```
   THIS SITE                        │            GAME SERVERS
   (identity)                       │            (play)
                                    │
   parent email                     │
   parent name                      │
   payment reference                │            minecraft_uuid
   consent record + version         │  ──────▶   cipher_token
   child play name                  │            age_band
   child age band                   │            entitlement
   cipher_token (issued here)       │
                                    │
   Never crosses ────────────────▶  │  ◀──────── Never returns
```

`schema/play-side.sql` is four columns and a long comment. It is the shortest honest
explanation of the entire platform's safety claim, and it is the file to show anybody who
asks whether the claim is real.

---

## What is here

```
parent-portal/
├── config.sample.php        copy to config.php and fill in — secrets live here, outside public/
├── schema/
│   ├── portal.sql           identity side: parents, consent, child profiles, join codes, audit
│   └── play-side.sql        play side: four columns, none of them identifying
├── src/
│   ├── bootstrap.php        config, database, session, CSRF, code and token generation
│   └── layout.php           page chrome, one place
└── public/                  ← the web root
    ├── index.php            the five steps
    └── assets/
        ├── app.css          dark theme, built from scratch
        └── app.js           copy buttons, share sheet, the voice rule. The page works without it.
```

Plain PHP and MySQL, deliberately. It has to run on a cPanel host with no build step and no
deploy pipeline, and it has to be readable by somebody checking whether the boundary is real.
A framework would bury the one thing this code exists to show.

---

## The five steps

1. **Your account** — email, name, password. *Nothing about a child is collected here.* A
   parent who stops at this step has told us nothing about a child, which matters most when
   they stopped because they changed their mind.
2. **What we collect** — the plain-language summary, as a two-column ledger: what we hold
   about you, what we hold about your child, what the game servers are told, what never
   leaves the site. Plus the three things we will not do.
3. **Confirm it is you** — the payment, which *is* the verifiable parental consent mechanism.
   No government ID, no video call, no credit quiz, because each of those would mean holding
   far more about a parent in order to prove something a card already proves.
4. **Your child** — play name (explicitly not their real one, with suggestions) and age band
   (**6–7** or **8–9**, never a date of birth), plus the three permissions.
5. **The keys** — the join code, the server address, which Minecraft they need, three steps
   in one sentence each, and a *text this to my kid* button. Emailed as well as shown.

The younger band forces voice on, in the form and again on the server. That is a platform
decision made elsewhere; this form enforces it rather than offering it.

---

## To stand it up

1. Create a database and a user with full rights on it, then run `schema/portal.sql`.
2. Copy `config.sample.php` to `config.php`, fill in the database details and the server
   address. Leave `payments_live` false until a processor exists.
3. Point the site's document root at `public/`. Nothing above it should ever be served —
   `config.php` holds the database password.
4. `schema/play-side.sql` goes on the game side, in its own database rather than sharing
   bento's. Its own costs a slot and buys the boundary argument, which is the point.

---

## Still to build

- **Email sending.** The verification token and the keys email are generated and stored; no
  mailer is wired up.
- **The redemption gate.** The holding area on the proxy where a child types the code, the
  portal API call that resolves it, and the hard rate limiting. `redeem_attempt` in
  `play-side.sql` is the table it needs.
- **The dashboard.** Play time, team and island, subscription state, pause, and the delete
  button. Deliberately *not* chat logs.
- **Deletion.** One button, and it must empty both sides, retain the consent record stripped
  of the person, and confirm by email.
- **Payment.** The hosted form mounts at `#payment-mount`; card details are entered on the
  processor's page and never on ours.

---

## Three open questions from the spec, still open

- **Hosting.** The spec argues the portal should not share a provider with the game servers —
  separate blast radius, simpler story when EU families arrive. It is being put on the web
  host instead, which is a different trade and worth making on purpose.
- **The school or club case.** Twenty children, one adult, twenty separate parent sign-ups
  does not work. There is a batch flow here and this is not it.
- **Does a parent get a Minecraft account** so they can come and look around? Probably yes,
  and it is not designed.

---

© 2026 MJD Entertainment, d/b/a The Cocoon Collective™. All rights reserved.
"The Cocoon Collective™" and cocoon-collective.org are trademarks of MJD Entertainment.
