<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Env;

/** Public page: download + install guide for the BC Android app. */
$apk = Env::get('APP_APK_URL', 'https://github.com/dhdhdh51/WhatsApp-api/releases/latest/download/bc-report.apk');
$local = is_file(__DIR__ . '/downloads/bc-report.apk') ? '/downloads/bc-report.apk' : null;
$url = $local ?? $apk;
$isIos = (bool) preg_match('/iPhone|iPad|iPod/i', $_SERVER['HTTP_USER_AGENT'] ?? '');
header('Cache-Control: no-store');
?><!doctype html>
<html lang="hi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>BC Report App Download</title>
<style>
body{font-family:system-ui,"Noto Sans Devanagari",sans-serif;background:#eef2f0;margin:0;color:#1d2b25}
.w{max-width:520px;margin:0 auto;padding:22px 16px}
.c{background:#fff;border-radius:14px;padding:18px;box-shadow:0 1px 3px rgba(0,0,0,.08);margin-bottom:14px}
h1{color:#0b8a5a;font-size:24px;margin:8px 0}
a.b{display:block;text-align:center;background:#0b8a5a;color:#fff;text-decoration:none;font-size:19px;font-weight:600;padding:16px;border-radius:12px;margin:14px 0}
ol{padding-left:20px;line-height:1.7} .m{color:#6b7a73;font-size:14px}
</style></head><body><div class="w">
<div class="c" style="text-align:center"><div style="margin:0 auto 10px;background:#0b8a5a;color:#fff;width:64px;height:64px;line-height:64px;border-radius:18px;font-size:22px;font-weight:800">BC</div><h1>BC Daily Report App</h1>
<p class="m">Roz ki report, aaj ka lakshya, suchna aur CBC / Admin se chat – sab ek app mein.</p>
<?php if ($isIos): ?>
<a class="b" href="/app/">iPhone / iPad par app kholein</a>
<?php else: ?>
<a class="b" href="<?= htmlspecialchars($url) ?>">App download karein (Android)</a>
<?php endif; ?></div>
<?php if ($isIos): ?>
<div class="c"><b>iPhone / iPad mein install kaise karein</b><ol>
<li>Yeh page <strong>Safari</strong> mein kholein.</li>
<li>Upar/neeche <strong>Share</strong> button dabayein.</li>
<li><strong>Add to Home Screen</strong> chunein, phir <strong>Add</strong> dabayein.</li>
<li>Home screen se <strong>BC Report</strong> kholein aur mobile number/password se login karein.</li>
</ol><p class="m">Is browser app mein report, chat aur history chalegi. iPhone par continuous alarm/call aur uninstall block ke liye native iOS app + Apple Business Manager/MDM required hai.</p></div>
<?php else: ?>
<div class="c"><b>Install kaise karein</b><ol>
<li>Upar ke button se app download karein.</li>
<li>Download hone par file <b>bc-report.apk</b> par tap karein.</li>
<li>Phone poochhe "Install unknown apps" – <b>Allow / Anumati dein</b> dabayein, phir wapas aakar <b>Install</b>.</li>
<li>App kholein, apna <b>mobile number</b> aur <b>password</b> daalein (password apne CBC / Admin se lein).</li>
<li>Pehli baar apna naya password banayein.</li>
</ol>
<p class="m">"Play Protect" warning aaye to "Install anyway / Phir bhi install karein" dabayein – ye app aapke bank ke BC kaam ke liye hai.</p></div>
<?php endif; ?>
<div class="c"><b>App nahi chal raha?</b><p class="m">Browser mein ye link kholkar bhi report bhar sakte hain:<br>
<a href="/app/"><?= htmlspecialchars(rtrim((string) Env::get('APP_URL', ''), '/')) ?>/app/</a></p></div>
</div></body></html>
