<?php
// Left visual panel for login / install: an exhibition hall floor plan.
$rows = ['A','B','C','D','E','F'];
$booked = ['A2','A5','B1','B3','B4','C2','C6','D1','D4','D5','E3','E6','F2','F4'];
$mine = ['B4','D4','E6'];
?>
<aside class="hero">
  <div class="hero-top">
    <img src="assets/logo-white.png" alt="Zest Exhibitions" class="hero-logo">
  </div>
  <svg class="plan" viewBox="0 0 440 330" aria-hidden="true">
    <defs>
      <pattern id="dots" width="10" height="10" patternUnits="userSpaceOnUse"><circle cx="1" cy="1" r="0.8" fill="rgba(255,255,255,.12)"/></pattern>
    </defs>
    <rect x="0" y="0" width="440" height="330" fill="url(#dots)"/>
    <rect x="6" y="6" width="428" height="318" rx="4" fill="none" stroke="rgba(255,255,255,.35)" stroke-width="1.2"/>
    <text x="16" y="24" class="pl-h">HALL 2 · FLOOR PLAN</text>
    <text x="424" y="24" text-anchor="end" class="pl-h">AISLE 3m</text>
    <?php foreach ($rows as $ri => $r):
      for ($c = 1; $c <= 6; $c++):
        $x = 18 + ($c - 1) * 68 + ($c > 3 ? 16 : 0); $y = 40 + $ri * 46;
        $id = $r . $c; $cls = in_array($id, $mine) ? 'st mine' : (in_array($id, $booked) ? 'st booked' : 'st');
    ?>
      <g class="<?= $cls ?>" style="animation-delay:<?= ($ri * 6 + $c) * 18 ?>ms">
        <rect x="<?= $x ?>" y="<?= $y ?>" width="60" height="38" rx="2"/>
        <text x="<?= $x + 6 ?>" y="<?= $y + 14 ?>"><?= $id ?></text>
        <?php if (in_array($id, $mine)): ?><text x="<?= $x + 6 ?>" y="<?= $y + 30 ?>" class="sq">24 sqm</text><?php endif; ?>
      </g>
    <?php endfor; endforeach; ?>
    <rect x="18" y="318" width="80" height="6" fill="rgba(255,255,255,.5)"/>
    <text x="104" y="324" class="pl-h">ENTRY</text>
  </svg>
  <div class="hero-copy">
    <h2>Every client, quote and stall in one place.</h2>
    <ul class="legend">
      <li><i class="lg mine"></i>Our stalls</li>
      <li><i class="lg booked"></i>Booked</li>
      <li><i class="lg"></i>Available</li>
    </ul>
  </div>
</aside>
