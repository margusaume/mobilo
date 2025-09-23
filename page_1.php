<?php
// Minimal PHP page for the "helloworld" link
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Page 1</title>
  <style>
    html, body { height: 100%; margin: 0; }
    body { display: grid; place-items: center; font-family: system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell, Noto Sans, Arial, Helvetica, "Apple Color Emoji", "Segoe UI Emoji"; }
    h1 { font-weight: 600; letter-spacing: .3px; }
    .container { text-align: center; }
    .sub { color: #666; font-size: 14px; margin-top: 8px; }
  </style>
</head>
<body>
  <main class="container">
    <h1><?php echo "Hello from PHP!"; ?></h1>
    <div class="sub">This is page_1.php opened from the helloworld link.</div>
    <div style="margin-top:16px">
      <a href="index.html">Back to index</a>
    </div>
  </main>
</body>
</html>



