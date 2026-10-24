<?php
/**
 * The Cocoon Collective™ — parent sign-up
 *
 * Five steps, one file, no framework.
 *
 *   account → consent → pay → child → keys
 *
 * The whole design is one boundary: everything collected here stays here. The
 * only things that ever reach a game server are a Mojang UUID, an opaque
 * token and an age band. See schema/play-side.sql — it is four columns and it
 * is the shortest explanation of this entire application.
 */

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/layout.php';

$step  = $_GET['step'] ?? 'account';
$error = null;

if (!array_key_exists($step, flow())) {
    $step = 'account';
}
if (!may_view($step)) {
    redirect(current_stage());
}

// ---------------------------------------------------------------------------
// POST handling
// ---------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $error = 'That form had gone stale. Please try again.';
    } else {
        switch ($step) {

            // -- 1 · the account ----------------------------------------------
            case 'account':
                $email = trim((string) ($_POST['email'] ?? ''));
                $name  = trim((string) ($_POST['display_name'] ?? ''));
                $pass  = (string) ($_POST['password'] ?? '');

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = 'That does not look like an email address we can reach you at.';
                } elseif ($name === '' || mb_strlen($name) > 80) {
                    $error = 'Please tell us what to call you.';
                } elseif (mb_strlen($pass) < 10) {
                    $error = 'Please use a password of at least ten characters. Longer is better than complicated.';
                } else {
                    try {
                        $st = db()->prepare(
                            'INSERT INTO parent (email, password_hash, display_name, status)
                             VALUES (?, ?, ?, "pending")'
                        );
                        $st->execute([$email, password_hash($pass, PASSWORD_DEFAULT), $name]);
                        $_SESSION['parent_id'] = (int) db()->lastInsertId();

                        // Email verification token. Stored hashed; the plain token is emailed.
                        $plain = bin2hex(random_bytes(24));
                        $st = db()->prepare(
                            'INSERT INTO email_token (parent_id, token_hash, purpose, expires_at)
                             VALUES (?, ?, "verify", DATE_ADD(NOW(), INTERVAL 24 HOUR))'
                        );
                        $st->execute([$_SESSION['parent_id'], hash('sha256', $plain)]);
                        $_SESSION['verify_token'] = $plain;   // handed to the mailer

                        audit($_SESSION['parent_id'], 'signup');
                        advance_to('consent');
                        redirect('consent');
                    } catch (PDOException $ex) {
                        $error = ((int) $ex->getCode() === 23000)
                            ? 'There is already an account with that email address.'
                            : 'Something went wrong saving that. Please try again.';
                    }
                }
                break;

            // -- 2 · the plain-language summary, then consent -------------------
            case 'consent':
                if (empty($_POST['agree'])) {
                    $error = 'Please confirm you have read the summary before continuing.';
                } else {
                    $_SESSION['read_summary'] = true;
                    advance_to('pay');
                    redirect('pay');
                }
                break;

            // -- 3 · payment, which IS the consent mechanism --------------------
            case 'pay':
                // The payment transaction is the verifiable parental consent
                // mechanism, which is why there is no government ID, no video
                // call and no knowledge-based quiz anywhere in this flow —
                // each of those would create a pile of sensitive data to
                // defend, in order to prove something the card already proves.
                //
                // Until a processor is connected, this records consent without
                // a charge so the rest of the flow can be walked end to end.
                $pid = (int) ($_SESSION['parent_id'] ?? 0);
                if ($pid <= 0) {
                    redirect('account');
                }

                $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                $st = db()->prepare(
                    'INSERT INTO consent
                        (parent_id, subject_ref, terms_version, mechanism, processor,
                         processor_ref, granted_at, ip_at_grant)
                     VALUES (?, ?, ?, "payment", ?, ?, NOW(), ' .
                        ($ip ? 'INET6_ATON(?)' : 'NULL') . ')'
                );
                $params = [
                    $pid,
                    hash('sha256', 'subject:' . $pid . ':' . bin2hex(random_bytes(8))),
                    terms_version(),
                    $_POST['processor']     ?? null,
                    $_POST['processor_ref'] ?? null,
                ];
                if ($ip) { $params[] = $ip; }
                $st->execute($params);

                db()->prepare('UPDATE parent SET status = "active" WHERE id = ?')->execute([$pid]);

                audit($pid, 'consent', 'terms ' . terms_version());
                advance_to('child');
                redirect('child');
                break;

            // -- 4 · the child profile ------------------------------------------
            case 'child':
                $pid   = (int) ($_SESSION['parent_id'] ?? 0);
                $dname = trim((string) ($_POST['display_name'] ?? ''));
                $band  = (string) ($_POST['age_band'] ?? '');

                if ($pid <= 0) {
                    redirect('account');
                } elseif ($dname === '' || mb_strlen($dname) > 40) {
                    $error = 'Please choose a name for your child to play under.';
                } elseif (!in_array($band, ['6-7', '8-9'], true)) {
                    $error = 'Please choose an age band.';
                } else {
                    // The younger band forces voice on. That is a platform
                    // decision made elsewhere: children this age cannot type
                    // reliably, and if there is no voice here they will go to
                    // another app where nobody is watching. This form enforces
                    // it rather than offering it.
                    $voice = ($band === '6-7') ? 1 : (int) !empty($_POST['consent_voice']);

                    $token = generate_cipher_token();
                    $st = db()->prepare(
                        'INSERT INTO child_profile
                            (parent_id, display_name, age_band, cipher_token,
                             consent_voice, consent_screens, consent_programme)
                         VALUES (?, ?, ?, ?, ?, ?, ?)'
                    );
                    $st->execute([
                        $pid, $dname, $band, $token, $voice,
                        (int) !empty($_POST['consent_screens']),
                        (int) !empty($_POST['consent_programme']),
                    ]);
                    $childId = (int) db()->lastInsertId();

                    // Issue the join code. Single use, 24 hours, stored hashed.
                    $code = generate_join_code();
                    $st = db()->prepare(
                        'INSERT INTO join_code (child_profile_id, code_hash, expires_at)
                         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))'
                    );
                    $st->execute([$childId, hash_code($code)]);

                    $_SESSION['child_id']   = $childId;
                    $_SESSION['join_code']  = $code;   // shown once, then only re-issuable
                    $_SESSION['child_name'] = $dname;

                    audit($pid, 'child_added', 'band ' . $band);
                    audit($pid, 'code_issued');
                    advance_to('keys');
                    redirect('keys');
                }
                break;
        }
    }
}

// ---------------------------------------------------------------------------
// Views
// ---------------------------------------------------------------------------

global $CONFIG;

switch ($step) {

case 'account':
    layout_top('account', 'Create your account');
    show_error($error);
    ?>
    <h1>First, an account for you.</h1>
    <p class="lede">
      Nothing about your child is collected on this page. If you stop here, we know
      nothing about them at all — which matters if you stop because you changed your mind.
    </p>

    <form method="post" action="?step=account" novalidate>
      <?= csrf_field() ?>

      <div class="field">
        <label class="lbl" for="email">Your email address</label>
        <p class="hint">Where the join code and anything important gets sent.</p>
        <input type="email" id="email" name="email" autocomplete="email" required
               value="<?= e($_POST['email'] ?? '') ?>" placeholder="you@example.com">
      </div>

      <div class="field">
        <label class="lbl" for="display_name">What should we call you?</label>
        <p class="hint">A first name is plenty.</p>
        <input type="text" id="display_name" name="display_name" autocomplete="given-name"
               required maxlength="80" value="<?= e($_POST['display_name'] ?? '') ?>">
      </div>

      <div class="field">
        <label class="lbl" for="password">Choose a password</label>
        <p class="hint">Ten characters or more. A short phrase you will remember beats
           something clever you will not.</p>
        <input type="password" id="password" name="password"
               autocomplete="new-password" required minlength="10">
      </div>

      <div class="actions">
        <button class="btn" type="submit">Continue</button>
      </div>
    </form>
    <?php
    layout_bottom();
    break;

case 'consent':
    layout_top('consent', 'What we collect');
    show_error($error);
    ?>
    <h1>Before you go further, here is the whole of it.</h1>
    <p class="lede">
      Written to be read at ten o'clock at night, not by a lawyer. The long version is in
      the terms; nothing in it contradicts this.
    </p>

    <div class="ledger">
      <div class="ledger__row">
        <div class="ledger__cell ledger__head">What we hold about you</div>
        <div class="ledger__cell ledger__head">What we hold about your child</div>
      </div>
      <div class="ledger__row">
        <div class="ledger__cell">
          <ul>
            <li>Your email and the name you gave us</li>
            <li>A record that you consented, and when</li>
            <li>A payment reference &mdash; never your card number</li>
          </ul>
        </div>
        <div class="ledger__cell">
          <ul>
            <li>A play name you choose, which should not be their real one</li>
            <li>An age band &mdash; not a date of birth</li>
            <li>What you have and have not allowed</li>
          </ul>
        </div>
      </div>
      <div class="ledger__row">
        <div class="ledger__cell ledger__head">What the game servers are told</div>
        <div class="ledger__cell ledger__head">What never leaves this site</div>
      </div>
      <div class="ledger__row">
        <div class="ledger__cell">
          <ul>
            <li>A Minecraft account id</li>
            <li>A random token that means nothing on its own</li>
            <li>The age band</li>
            <li>Whether play is allowed right now</li>
          </ul>
        </div>
        <div class="ledger__cell">
          <ul>
            <li>Your name, your email, your payment details</li>
            <li>Your child's play name</li>
            <li>Anything at all that could name a person</li>
          </ul>
        </div>
      </div>
    </div>

    <div class="note">
      <strong>Why it is split like that.</strong> We intend the game servers to hold nothing
      that identifies a child, so that a break-in at the game hosting company is not a
      break-in on your family. That only means something if it is built that way rather
      than promised, which is why the two halves are separate and why the list above is
      short.
    </div>

    <h2>Three things we will not do</h2>
    <div class="note">
      <strong>We will not show you your child's chat.</strong> A dashboard that hands a
      parent a child's conversations on demand is surveillance, and it teaches a child that
      nothing they say anywhere is private &mdash; the opposite of the instinct we want them
      to grow. When a pattern warrants it, it goes to a person, not to a feed.
      <br><br>
      <strong>We will not ask for a date of birth.</strong> A band is enough to run the
      platform properly. A date next to a name starts to behave like an identity document.
      <br><br>
      <strong>We will not use anything your child does to train an AI model.</strong> Not
      ours, not anybody's.
    </div>

    <form method="post" action="?step=consent">
      <?= csrf_field() ?>
      <label class="toggle">
        <input type="checkbox" name="agree" value="1" required>
        <span>
          <span class="toggle__title">I have read the summary above.</span>
          <span class="toggle__note">You will confirm consent on the next page, and you can
            withdraw it at any time from your dashboard.</span>
        </span>
      </label>
      <div class="actions">
        <button class="btn" type="submit">Continue</button>
      </div>
    </form>
    <?php
    layout_bottom();
    break;

case 'pay':
    layout_top('pay', 'Confirm it is you');
    show_error($error);
    ?>
    <h1>Confirming you are the grown-up.</h1>
    <p class="lede">
      The law asks us to verify that a real parent agreed to this, and a card in a parent's
      name is how we do it. One step, and it is the subscription at the same time.
    </p>

    <div class="note">
      <strong>What this avoids.</strong> The alternative ways of proving a parent is a
      parent &mdash; a photograph of a driving licence, a video call, a quiz about your
      credit history &mdash; would all mean us holding far more about you than we want to.
      Using the payment means we hold a reference number and nothing else. Your card
      details never touch this site.
    </div>

    <?php if (!empty($CONFIG['payments_live'])): ?>
      <!-- A processor is connected: its own hosted form is mounted here, so card
           details are entered on the processor's page and never on ours. -->
      <div id="payment-mount" data-processor="<?= e($CONFIG['processor']) ?>"></div>
      <form method="post" action="?step=pay" id="pay-form">
        <?= csrf_field() ?>
        <input type="hidden" name="processor" value="<?= e($CONFIG['processor']) ?>">
        <input type="hidden" name="processor_ref" id="processor_ref" value="">
        <div class="actions">
          <button class="btn" type="submit">Confirm and continue</button>
        </div>
      </form>
    <?php else: ?>
      <div class="note">
        <strong>No payment processor is connected yet.</strong> We plan to take the
        subscription at this step. Until that is wired up, continuing records your consent
        and charges nothing, so the rest of the sign-up can be walked end to end.
      </div>
      <form method="post" action="?step=pay">
        <?= csrf_field() ?>
        <div class="actions">
          <button class="btn" type="submit">I agree, and I am this child's parent or guardian</button>
        </div>
      </form>
    <?php endif; ?>
    <?php
    layout_bottom();
    break;

case 'child':
    layout_top('child', 'Your child');
    show_error($error);
    ?>
    <h1>Now, your child.</h1>
    <p class="lede">Two things, and one of them is a choice between two buttons.</p>

    <form method="post" action="?step=child" novalidate>
      <?= csrf_field() ?>

      <div class="field">
        <label class="lbl" for="cname">What should they be called in the game?</label>
        <p class="hint">
          <strong>Please do not use their real name.</strong> Everyone they play with will
          see this. Something they picked themselves works best &mdash; Pebble, RocketFrog,
          Captain Toast, BlueHeron.
        </p>
        <input type="text" id="cname" name="display_name" required maxlength="40"
               placeholder="Pebble" value="<?= e($_POST['display_name'] ?? '') ?>">
      </div>

      <div class="field">
        <label class="lbl">How old are they?</label>
        <p class="hint">A band, not a birthday. We do not ask for a date of birth and we do
           not want one.</p>
        <div class="choices">
          <label class="choice">
            <input type="radio" name="age_band" value="6-7" required
                   <?= (($_POST['age_band'] ?? '') === '6-7') ? 'checked' : '' ?>>
            <span class="choice__title">6 to 7</span>
            <span class="choice__note">Voice is always on for this age &mdash; see below.</span>
          </label>
          <label class="choice">
            <input type="radio" name="age_band" value="8-9"
                   <?= (($_POST['age_band'] ?? '') === '8-9') ? 'checked' : '' ?>>
            <span class="choice__title">8 to 9</span>
            <span class="choice__note">You choose whether voice is on.</span>
          </label>
        </div>
      </div>

      <h2>What you allow</h2>

      <label class="toggle" id="voice-toggle">
        <input type="checkbox" name="consent_voice" value="1" id="voice-box">
        <span>
          <span class="toggle__title">Talking to other players by voice</span>
          <span class="toggle__note" id="voice-note">
            We intend all voice to run inside the platform, where it can be monitored. A
            six- or seven-year-old cannot type fast enough to play with a friend, and if
            there is no voice here they will use a phone or another app where nobody is
            looking after them. For that age we turn it on rather than offering it.
          </span>
        </span>
      </label>

      <label class="toggle">
        <input type="checkbox" name="consent_screens" value="1">
        <span>
          <span class="toggle__title">Screenshots of things they build</span>
          <span class="toggle__note">So we may show their work in the Guild Hall. Their play
            name appears; nothing else does.</span>
        </span>
      </label>

      <label class="toggle">
        <input type="checkbox" name="consent_programme" value="1">
        <span>
          <span class="toggle__title">Occasional news from us</span>
          <span class="toggle__note">New things to play, and nothing else. To you, not to
            them.</span>
        </span>
      </label>

      <div class="actions">
        <button class="btn" type="submit">Get the join code</button>
      </div>
    </form>
    <?php
    layout_bottom();
    break;

case 'keys':
    $code  = $_SESSION['join_code']  ?? null;
    $cname = $_SESSION['child_name'] ?? 'your child';
    if (!$code) {
        redirect('account');
    }
    layout_top('keys', 'The keys');
    ?>
    <h1>That is everything. Here are the keys.</h1>
    <p class="lede">
      This has gone to your email as well, because you are probably on a laptop and
      <?= e($cname) ?> is probably on the machine in the other room.
    </p>

    <div class="keycard">
      <div class="keycard__label">Join code</div>
      <p class="keycard__code" id="joincode"><?= e($code) ?></p>
      <p class="keycard__expiry">Works once, and only for the next 24 hours.</p>
      <button class="btn btn--small" type="button" data-copy="#joincode">Copy the code</button>
    </div>

    <div class="copyrow">
      <span class="copyrow__k">Server</span>
      <span class="copyrow__v" id="serveraddr"><?= e($CONFIG['server_address']) ?></span>
      <button class="btn btn--quiet btn--small" type="button" data-copy="#serveraddr">Copy</button>
    </div>
    <div class="copyrow">
      <span class="copyrow__k">Edition</span>
      <span class="copyrow__v">Minecraft: Java Edition, <?= e($CONFIG['minecraft_version']) ?></span>
    </div>

    <h2>What happens next</h2>
    <ol class="nextsteps">
      <li>On <?= e($cname) ?>'s computer, open <b>Minecraft: Java Edition</b> and choose
          <b>Multiplayer</b>, then <b>Add Server</b>.</li>
      <li>Paste the server address above and join. They will land in a small waiting room
          with one friendly face in it &mdash; not the game yet.</li>
      <li>They type the join code. That is it &mdash; the door opens and the waiting room
          is never seen again.</li>
    </ol>

    <div class="note">
      <strong>Bedtime is yours, not ours.</strong> Nothing here logs anybody out. We plan to
      put play-time limits in your dashboard so you can set them once rather than argue
      about them nightly.
    </div>

    <div class="actions">
      <button class="btn" type="button" data-share
              data-text="Your Cocoon Collective join code is <?= e($code) ?>. Server: <?= e($CONFIG['server_address']) ?>">
        Text this to my kid
      </button>
      <a class="btn btn--quiet" href="<?= e($CONFIG['dashboard_url']) ?>">Go to my dashboard</a>
    </div>
    <?php
    layout_bottom();
    break;
}
