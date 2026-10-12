package site.bharatseo.bcreport;

import android.Manifest;
import android.annotation.SuppressLint;
import android.app.Activity;
import android.content.ActivityNotFoundException;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.graphics.Color;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.view.Gravity;
import android.view.View;
import android.view.WindowInsets;
import android.webkit.JavascriptInterface;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.Button;
import android.widget.FrameLayout;
import android.widget.LinearLayout;
import android.widget.TextView;

/** Hosts the BC web app (/app/) and bridges login state to the background notification checker. */
public class MainActivity extends Activity {

    private WebView web;
    private LinearLayout offline;

    @SuppressLint("SetJavaScriptEnabled")
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        Notifier.createChannel(this);

        FrameLayout root = new FrameLayout(this);
        web = new WebView(this);
        root.addView(web, new FrameLayout.LayoutParams(-1, -1));
        offline = buildOfflineView();
        offline.setVisibility(View.GONE);
        root.addView(offline, new FrameLayout.LayoutParams(-1, -1));
        setContentView(root);
        applyInsets(root);

        WebSettings ws = web.getSettings();
        ws.setJavaScriptEnabled(true);
        ws.setDomStorageEnabled(true);
        ws.setAllowFileAccess(false);
        ws.setAllowContentAccess(false);
        ws.setUserAgentString(ws.getUserAgentString() + " BCReportApp/" + BuildConfig.VERSION_NAME);
        web.addJavascriptInterface(new Bridge(), "BCNative");
        web.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                Uri u = request.getUrl();
                String base = Uri.parse(BuildConfig.BASE_URL).getHost();
                if (u.getHost() != null && u.getHost().equals(base) && "https".equals(u.getScheme())) {
                    return false; // stay inside the app
                }
                try {
                    startActivity(new Intent(Intent.ACTION_VIEW, u)); // tel:, wa.me, other sites
                } catch (ActivityNotFoundException ignored) {
                }
                return true;
            }

            @Override
            public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError error) {
                if (request.isForMainFrame()) {
                    offline.setVisibility(View.VISIBLE);
                }
            }

            @Override
            public void onPageFinished(WebView view, String url) {
                if (!url.startsWith("data:")) {
                    offline.setVisibility(View.GONE);
                }
            }
        });

        load(getIntent());
        askNotificationPermission();
        PollWorker.schedule(this);
        AlarmScheduler.scheduleAll(this);
        PushService.refresh(this);
        askFullScreenPermission();
    }

    @Override
    protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        load(intent);
    }

    private void load(Intent intent) {
        String hash = intent != null ? intent.getStringExtra("hash") : null;
        web.loadUrl(BuildConfig.BASE_URL + "/app/" + (hash != null ? "#" + hash : ""));
    }

    /**
     * Android 15 draws apps edge-to-edge: keep the page clear of the status bar, the system
     * navigation buttons and the keyboard, so the bottom tabs and the chat box stay visible.
     */
    private void applyInsets(View root) {
        root.setOnApplyWindowInsetsListener((v, insets) -> {
            int top;
            int bottom;
            int left;
            int right;
            if (Build.VERSION.SDK_INT >= 30) {
                android.graphics.Insets bars = insets.getInsets(WindowInsets.Type.systemBars() | WindowInsets.Type.displayCutout());
                android.graphics.Insets ime = insets.getInsets(WindowInsets.Type.ime());
                top = bars.top;
                left = bars.left;
                right = bars.right;
                bottom = Math.max(bars.bottom, ime.bottom);
            } else {
                top = insets.getSystemWindowInsetTop();
                left = insets.getSystemWindowInsetLeft();
                right = insets.getSystemWindowInsetRight();
                bottom = insets.getSystemWindowInsetBottom();
            }
            v.setPadding(left, top, right, bottom);
            return insets;
        });
        root.setBackgroundColor(Color.parseColor("#0B8A5A"));
        root.requestApplyInsets();
    }

    private LinearLayout buildOfflineView() {
        LinearLayout l = new LinearLayout(this);
        l.setOrientation(LinearLayout.VERTICAL);
        l.setGravity(Gravity.CENTER);
        l.setBackgroundColor(Color.WHITE);
        l.setPadding(48, 48, 48, 48);
        TextView t = new TextView(this);
        t.setText(R.string.offline);
        t.setTextSize(18);
        t.setGravity(Gravity.CENTER);
        Button b = new Button(this);
        b.setText(R.string.retry);
        b.setOnClickListener(v -> {
            offline.setVisibility(View.GONE);
            web.reload();
        });
        l.addView(t);
        l.addView(b);
        return l;
    }

    private void askNotificationPermission() {
        if (Build.VERSION.SDK_INT >= 33
                && checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) {
            requestPermissions(new String[]{Manifest.permission.POST_NOTIFICATIONS}, 1);
        }
    }

    /** Android 14+: allow the ringing screen to open over the lock screen. */
    private void askFullScreenPermission() {
        if (Build.VERSION.SDK_INT < 34 || Prefs.token(this).isEmpty()) {
            return;
        }
        android.app.NotificationManager nm = getSystemService(android.app.NotificationManager.class);
        if (nm != null && !nm.canUseFullScreenIntent()) {
            new android.app.AlertDialog.Builder(this)
                    .setTitle("Call / alarm ki permission")
                    .setMessage("Report yaad dilane wali call aur alarm lock screen par dikhe, iske liye agle page par BC Report ko Allow karein.")
                    .setPositiveButton("Allow karein", (d, w) -> openRingSettings())
                    .setNegativeButton("Baad mein", null)
                    .show();
        }
    }

    private void openRingSettings() {
        try {
            if (Build.VERSION.SDK_INT >= 34) {
                startActivity(new Intent(android.provider.Settings.ACTION_MANAGE_APP_USE_FULL_SCREEN_INTENT, Uri.parse("package:" + getPackageName())));
            } else {
                startActivity(new Intent(android.provider.Settings.ACTION_APP_NOTIFICATION_SETTINGS)
                        .putExtra(android.provider.Settings.EXTRA_APP_PACKAGE, getPackageName()));
            }
        } catch (ActivityNotFoundException ignored) {
        }
    }

    @Override
    public void onBackPressed() {
        if (web.canGoBack()) {
            web.goBack();
        } else {
            super.onBackPressed();
        }
    }

    /** Called from app.js after login / logout. */
    private class Bridge {
        @JavascriptInterface
        public void setSession(String token, String origin, String last) {
            Prefs.saveSession(MainActivity.this, token, last);
            PollWorker.schedule(MainActivity.this);
            PollWorker.runNow(MainActivity.this);
            PushService.refresh(MainActivity.this);
        }

        @JavascriptInterface
        public void setReportDone(String date) {
            Prefs.setReportDone(MainActivity.this, date);
        }

        @JavascriptInterface
        public void testRing(String mode) {
            Ringer.ring(MainActivity.this, "call".equals(mode) ? "call" : "alarm");
        }

        @JavascriptInterface
        public void ringSettings() {
            runOnUiThread(MainActivity.this::openRingSettings);
        }

        @JavascriptInterface
        public String ringInfo() {
            return Prefs.callTime(MainActivity.this) + "|" + Prefs.alarmTime(MainActivity.this) + "|" + (Prefs.alarmEnabled(MainActivity.this) ? "1" : "0");
        }

        @JavascriptInterface
        public void clearSession() {
            Prefs.clear(MainActivity.this);
        }
    }
}
