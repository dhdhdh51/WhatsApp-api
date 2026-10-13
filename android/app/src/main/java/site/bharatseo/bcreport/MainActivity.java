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
                String path = u.getPath() != null ? u.getPath() : "";
                if (u.getHost() != null && u.getHost().equals(base) && "https".equals(u.getScheme()) && !path.endsWith(".apk")) {
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

        // downloads (app update APK) go to the phone's browser / download manager
        web.setDownloadListener((url, ua, cd, mime, len) -> {
            try {
                startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse(url)));
            } catch (ActivityNotFoundException ignored) {
            }
        });
        load(getIntent());
        // Notification permission is requested only after a BC actually logs in.
        PollWorker.schedule(this);
        AlarmScheduler.scheduleAll(this);
        PushService.refresh(this);
        Mdm.enforce(this);
        Mdm.report(this, Prefs.token(this));
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
            runOnUiThread(MainActivity.this::askNotificationPermission);
            PollWorker.schedule(MainActivity.this);
            PollWorker.runNow(MainActivity.this);
            PushService.refresh(MainActivity.this);
            AlarmScheduler.scheduleAll(MainActivity.this);
            Mdm.enforce(MainActivity.this);
            Mdm.report(MainActivity.this, token);
        }

        @JavascriptInterface
        public void setReportDone(String date) {
            Prefs.setReportDone(MainActivity.this, date);
            AlarmScheduler.cancelRepeat(MainActivity.this); // report is in -> no more alarm today
            Ringer.stopActive(MainActivity.this);
        }

        @JavascriptInterface
        public void clearSession() {
            Prefs.clear(MainActivity.this);
        }
    }
}
