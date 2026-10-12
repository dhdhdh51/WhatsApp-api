package site.bharatseo.bcreport;

import android.app.Activity;
import android.content.Intent;
import android.graphics.Color;
import android.graphics.Typeface;
import android.media.AudioAttributes;
import android.media.MediaPlayer;
import android.media.Ringtone;
import android.media.RingtoneManager;
import android.os.Build;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.os.VibrationEffect;
import android.os.Vibrator;
import android.speech.tts.TextToSpeech;
import android.speech.tts.UtteranceProgressListener;
import android.view.Gravity;
import android.view.WindowManager;
import android.widget.Button;
import android.widget.LinearLayout;
import android.widget.TextView;

import java.io.File;
import java.util.Locale;

/**
 * "Incoming call" screen (call mode: ring until Accept, then the recorded message plays)
 * or alarm screen (alarm mode: the message plays on repeat until dismissed).
 */
public class RingActivity extends Activity {

    private static final AudioAttributes LOUD = new AudioAttributes.Builder()
            .setUsage(AudioAttributes.USAGE_ALARM).setContentType(AudioAttributes.CONTENT_TYPE_SPEECH).build();

    private final Handler handler = new Handler(Looper.getMainLooper());
    private Ringtone tone;
    private MediaPlayer player;
    private TextToSpeech tts;
    private Vibrator vibrator;
    private boolean alarm;
    private boolean finished;
    private LinearLayout buttons;
    private TextView status;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        if (Build.VERSION.SDK_INT >= 27) {
            setShowWhenLocked(true);
            setTurnScreenOn(true);
        } else {
            getWindow().addFlags(WindowManager.LayoutParams.FLAG_SHOW_WHEN_LOCKED | WindowManager.LayoutParams.FLAG_TURN_SCREEN_ON);
        }
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
        Ringer.cancel(this); // stop the notification sound, this screen takes over
        alarm = !"call".equals(getIntent().getStringExtra("mode"));
        vibrator = getSystemService(Vibrator.class);

        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setGravity(Gravity.CENTER);
        root.setBackgroundColor(Color.parseColor(alarm ? "#8A3B0B" : "#0B8A5A"));
        root.setPadding(48, 96, 48, 96);

        TextView icon = text(alarm ? "⏰" : "📞", 64, true);
        TextView title = text("BC Report", 30, true);
        TextView sub = text(alarm ? "Aaj ki report abhi baaki hai!" : "BC Supervisor – call", 18, false);
        status = text(alarm ? "Sandesh chal raha hai…" : "Call aa rahi hai…", 16, false);
        root.addView(icon);
        root.addView(title);
        root.addView(sub);
        root.addView(status);

        buttons = new LinearLayout(this);
        buttons.setOrientation(LinearLayout.VERTICAL);
        buttons.setPadding(0, 64, 0, 0);
        root.addView(buttons, new LinearLayout.LayoutParams(-1, -2));
        setContentView(root);

        if (alarm) {
            vibrate();
            playMessage(true);
            showAfterButtons();
        } else {
            startTone();
            addButton("📞  Call uthayein", "#2E7D32", v -> accept());
            addButton("Kaat dein", "#C62828", v -> done());
        }
        handler.postDelayed(this::done, 3 * 60 * 1000); // stop after 3 minutes in any case
    }

    private TextView text(String s, int sp, boolean bold) {
        TextView t = new TextView(this);
        t.setText(s);
        t.setTextSize(sp);
        t.setTextColor(Color.WHITE);
        t.setGravity(Gravity.CENTER);
        t.setPadding(0, 12, 0, 12);
        if (bold) {
            t.setTypeface(Typeface.DEFAULT_BOLD);
        }
        return t;
    }

    private void addButton(String label, String color, android.view.View.OnClickListener l) {
        Button b = new Button(this);
        b.setText(label);
        b.setTextSize(20);
        b.setTextColor(Color.WHITE);
        b.setAllCaps(false);
        b.setBackgroundColor(Color.parseColor(color));
        b.setOnClickListener(l);
        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(-1, -2);
        lp.setMargins(0, 16, 0, 16);
        buttons.addView(b, lp);
    }

    private void showAfterButtons() {
        buttons.removeAllViews();
        addButton("📋  Report bharein", "#1565C0", v -> openReport());
        if (!alarm) {
            addButton("🔁  Dobara sunein", "#2E7D32", v -> playMessage(false));
        }
        addButton("Band karein", "#455A64", v -> done());
    }

    private void accept() {
        stopTone();
        status.setText("Sandesh sunein…");
        playMessage(false);
        showAfterButtons();
    }

    private void startTone() {
        tone = RingtoneManager.getRingtone(this, Ringer.uri(RingtoneManager.TYPE_RINGTONE));
        if (tone != null) {
            tone.setAudioAttributes(new AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_NOTIFICATION_RINGTONE).build());
            if (Build.VERSION.SDK_INT >= 28) {
                tone.setLooping(true);
            }
            tone.play();
        }
        vibrate();
    }

    private void vibrate() {
        if (vibrator != null && vibrator.hasVibrator()) {
            vibrator.vibrate(VibrationEffect.createWaveform(new long[]{0, 900, 700}, 0));
        }
    }

    private void stopTone() {
        if (tone != null) {
            tone.stop();
            tone = null;
        }
        if (vibrator != null) {
            vibrator.cancel();
        }
    }

    /** Recorded audio if downloaded, otherwise the text in Hindi voice. */
    private void playMessage(boolean loop) {
        stopMessage();
        File f = new File(getFilesDir(), alarm ? "alarm_audio" : "call_audio");
        if (alarm && !(f.exists() && f.length() > 0) && !Prefs.alarmOnlyText(this).isEmpty()) {
            f = new File(getFilesDir(), "no_file"); // alarm text set, no alarm audio -> speak alarm text
        } else if (alarm && !(f.exists() && f.length() > 0)) {
            f = new File(getFilesDir(), "call_audio"); // nothing for alarm -> use the call audio
        }
        if (f.exists() && f.length() > 0) {
            try {
                player = new MediaPlayer();
                player.setAudioAttributes(LOUD);
                player.setDataSource(f.getPath());
                player.prepare();
                player.setOnCompletionListener(mp -> {
                    if (loop && !finished) {
                        handler.postDelayed(() -> {
                            if (!finished && player != null) {
                                player.seekTo(0);
                                player.start();
                            }
                        }, 1500);
                    }
                });
                player.start();
                return;
            } catch (Exception e) {
                stopMessage();
            }
        }
        speak(loop);
    }

    private void speak(boolean loop) {
        final String own = alarm && !Prefs.alarmOnlyText(this).isEmpty() ? Prefs.alarmOnlyText(this) : Prefs.alarmText(this);
        final String msg = own.isEmpty()
                ? "Namaste. Aapki aaj ki report abhi tak nahi aayi hai. Kripya abhi BC Report app kholkar report bhejein."
                : own;
        tts = new TextToSpeech(this, st -> {
            if (st != TextToSpeech.SUCCESS || tts == null) {
                return;
            }
            int r = tts.setLanguage(new Locale("hi", "IN"));
            if (r == TextToSpeech.LANG_MISSING_DATA || r == TextToSpeech.LANG_NOT_SUPPORTED) {
                tts.setLanguage(Locale.getDefault());
            }
            tts.setAudioAttributes(LOUD);
            tts.setOnUtteranceProgressListener(new UtteranceProgressListener() {
                @Override
                public void onStart(String id) {
                }

                @Override
                public void onDone(String id) {
                    if (loop && !finished) {
                        handler.postDelayed(() -> {
                            if (!finished && tts != null) {
                                tts.speak(msg, TextToSpeech.QUEUE_FLUSH, null, "bc");
                            }
                        }, 1500);
                    }
                }

                @Override
                public void onError(String id) {
                }
            });
            tts.speak(msg, TextToSpeech.QUEUE_FLUSH, null, "bc");
        });
    }

    private void stopMessage() {
        if (player != null) {
            try {
                player.stop();
            } catch (Exception ignored) {
            }
            player.release();
            player = null;
        }
        if (tts != null) {
            tts.stop();
            tts.shutdown();
            tts = null;
        }
    }

    private void openReport() {
        startActivity(new Intent(this, MainActivity.class).putExtra("hash", "home")
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TOP));
        done();
    }

    private void done() {
        if (finished) {
            return;
        }
        finished = true;
        handler.removeCallbacksAndMessages(null);
        stopTone();
        stopMessage();
        finish();
    }

    @Override
    protected void onDestroy() {
        finished = true;
        handler.removeCallbacksAndMessages(null);
        stopTone();
        stopMessage();
        super.onDestroy();
    }
}
