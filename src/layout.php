<?php
/**
 * Page chrome. One file, so the look is decided in one place.
 *
 * Usage:  layout_top('keys'); ... echo page body ... layout_bottom();
 */

declare(strict_types=1);

function layout_top(string $stage, string $title = ''): void
{
    $flow  = flow();
    $order = array_keys($flow);
    $here  = array_search($stage, $order, true);
    $full  = $title !== '' ? $title . ' · ' : '';
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="dark">
<title><?= e($full) ?>The Cocoon Collective&trade; — parent sign-up</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>

<header class="masthead">
  <div class="masthead__inner">
    <a class="wordmark" href="https://www.cocoon-collective.org">The <b>Cocoon&nbsp;Collective</b>&trade;</a>
    <span class="masthead__aside">Parent sign-up</span>
  </div>
</header>

<main>
  <div class="panel">
    <ul class="steps" aria-hidden="true">
      <?php foreach ($order as $i => $key): ?>
        <li class="<?= $i < $here ? 'done' : ($i === $here ? 'here' : '') ?>"></li>
      <?php endforeach; ?>
    </ul>
    <p class="steps__label">
      Step <?= (int) $here + 1 ?> of <?= count($order) ?> &middot; <?= e($flow[$stage]) ?>
    </p>
<?php
}

function layout_bottom(): void
{
    ?>
  </div>
</main>

<footer>
  <div class="footer__inner">
    <p>
      Questions, or something not working?
      <a href="mailto:cocoon-help@outlook.com">cocoon-help@outlook.com</a>
    </p>
    <p>
      &copy; 2026 MJM Productions. All rights reserved.
      &ldquo;The Cocoon Collective&trade;&rdquo; and cocoon-collective.org are trademarks
      owned by MJM Productions.
    </p>
  </div>
</footer>

<script src="assets/app.js" defer></script>
</body>
</html>
<?php
}

function show_error(?string $msg): void
{
    if ($msg) {
        echo '<div class="error">' . e($msg) . '</div>';
    }
}
