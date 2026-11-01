# Handoff: kaam kahan tak pahuncha (1 Nov 2026, 18:36 IST)

Pichhla chat bahut lamba ho gaya tha, isliye kaam naye session mein aage badhega. Ye file us naye session ke liye hai: pehle ye poori padhein, phir aage ka kaam karein. Repo public hai, isliye isme koi password ya secret nahi hai, aur kabhi daalna bhi nahi hai.

## Naya session kaise shuru karein (user ke liye)

1. Kiro mein naya session kholein. Mode: **Vibe**.
2. Repository: **`dhdhdh51/WhatsApp-api`**, branch **`feature/live-broadcast`**. Website ka kaam ho to `dhdhdh51/Noidarankers` bhi jod lein.
3. Pehla message ye bhejein (IP apna likhein):

   > `deploy/HANDOFF.md` padho aur wahan se kaam aage badhao. Naye server ka IP: ______

## Project

- **BC Monitoring App**: PHP 8, MariaDB, LiveKit (video meeting/broadcast), WAHA (WhatsApp, Docker), Firebase push, Android app. Production address `bc.bharatseo.site`. Android app mein yahi address fix hai, isliye domain mat badlein.
- **noidarankers.in**: alag repo `dhdhdh51/Noidarankers`, branch `feature/noidarankers-website`. Ye usi purane server par chalti thi.
- Branch `feature/live-broadcast`, PR #1 (base `feature/bc-daily-reporting`): https://github.com/dhdhdh51/WhatsApp-api/pull/1
- Purane production par aakhri deploy commit **`e0c9673`** tha. Uske baad app ka code nahi badla. Sirf docs aur nginx template badle hain (`36ffaf9` aur ye file).
- User Hinglish ya Devanagari mein likhte hain. Jawab Hinglish mein dein. Screen ke text Devanagari mein ho sakte hain.

## Kya hua (incident)

- Purana production: AWS EC2 us-east-1, IP `34.231.49.225`, aaPanel + OpenLiteSpeed, PHP 8.4, MariaDB, LiveKit 1.13.10. Dono domain ka DNS (`ns1/ns2.bharatseo.site`) bhi isi server par chalta tha.
- 1 Nov ko ~03:40 IST se server band hai, kyunki **AWS account suspend ho gaya**. 18:36 IST par bhi koi port jawab nahi de raha tha.
- Saara data **sirf purane server ki disk par** hai: database, `.env`, Firebase key (`storage/firebase-sa.json`), uploads aur WhatsApp login (`waha/sessions`). Code poora GitHub par hai.
- AWS ke niyam: suspend hone ke 30 din mein bill nahi bhara to AWS account band kar sakta hai. Band hone ke 60 din baad data delete ho jaata hai. User ne naya AWS account bana liya hai, lekin purana data purane account mein hi hai.
- Domain: `bharatseo.site` Hostinger par registered hai, `noidarankers.in` GoDaddy par. Dono ke nameserver abhi bhi band server (`ns1/ns2.bharatseo.site`) par hain, isliye nameserver badalne honge (`deploy/NEW-SERVER.md` step 2).

## Naya server (abhi ki halat)

- **EricHost**: user ne cPanel **shared hosting** li hai (`server156`, IP `15.235.36.121`, OVH Beauharnois, Canada). Is par LiveKit, coturn aur WAHA nahi chal sakte. User ko Mumbai VPS, upgrade ya refund ki salah di gayi; faisla abhi baaki hai. EricHost portal ka login reCAPTCHA se surakshit hai: use bypass karne ki koshish na karein.
- **Naya AWS account**: server `13.211.204.36` hai: Sydney (ap-southeast-2), Ubuntu 24.04, sirf port 22 khula, sirf key se login. Host key ED25519 `SHA256:z4jTi99u4XOfzKjOr+YTZA0TjKff4dsgVeNxz85U/68`. 18:32 IST tak agent ki key is par nahi judi thi.
- Uske baad user ne kaha ki unhone **ek aur naya server** banaya hai, naye IP ke saath. Wo IP abhi tak nahi mila.
- User ko di gayi salah:
  - Region **Mumbai (ap-south-1)** rakhein. India se Sydney ~290 ms door hai.
  - Server **t3.large** (2 vCPU / 8 GB, ~$65/mahina), disk 40 GB gp3, aur **Elastic IP**.
  - Account Free plan se **Paid plan** par karein. Free plan credit khatam hone par ya 6 mahine baad account apne aap band ho jaata hai.
  - **Budget alert** lagayein.
  - Video ka data: $0.1093/GB (Mumbai) ya $0.114/GB (Sydney), har mahine pehle 100 GB free. Andaza: 100 camera wala 1 ghante ka broadcast 50–80 GB.
- Security group inbound rules: TCP 22, 80, 443, 7881; UDP 7882; TCP+UDP 3478; UDP 49160–49200 (sab `0.0.0.0/0`).

## Server tak pahunch (SSH)

- Pichhle session ki key: `/root/.cache/devenv/golive/ssh/id_bc_golive`, fingerprint `SHA256:gGEzj+mO4ok2rmB5JPTtO46eb1Uv5tyizFYqh0/RdIg`. Public key:
  `ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIPjPldvb5ptcr2pD7hvYKgLhbZfuw3lTMSTASlI8CFOe bc-golive-setup`
- Naye session ke sandbox mein ye file na mile to `ssh-keygen -t ed25519` se nayi key banayein, aur user ko neeche wali command nayi public key ke saath dein.
- User key aise jodte hain: AWS console → EC2 → Instances → server → **Connect** → **EC2 Instance Connect** → user `ubuntu`. Phir ye ek command (`K=` mein public key):

```
mkdir -p ~/.ssh && chmod 700 ~/.ssh && K='<PUBLIC KEY>' && { grep -qxF "$K" ~/.ssh/authorized_keys 2>/dev/null || printf '\n%s\n' "$K" >> ~/.ssh/authorized_keys; } && chmod 600 ~/.ssh/authorized_keys && . /etc/os-release && echo "OK | $(whoami) | $PRETTY_NAME | $(nproc) CPU | $(free -m | awk '/^Mem:/{print $2}') MB RAM | disk $(df -h / | awk 'NR==2{print $2}') | IP $(curl -4 -s --max-time 10 https://ifconfig.me)"
```

- Command ke baad `OK | ubuntu | Ubuntu 24.04 ... | IP <server IP>` aana chahiye. `cloudshell-user` dikhe to command AWS CloudShell mein chali hai, server par nahi.
- User se kabhi password ya private key (`.pem`) chat mein na mangein.

## Aage ka kaam (kram se)

1. Naye server ka IP aur OK line lein. `ssh ubuntu@IP` se login karein, phir `sudo -i`.
2. `deploy/NEW-SERVER.md` follow karein. Ye guide local par test ki hui hai. AWS par ye badlav karne honge:
   - ufw ke saath AWS **security group** mein bhi ports khulne chahiye.
   - `/root/golive.env` mein `NEW_IP` = **Elastic IP**. LiveKit `node_ip` yahi IP leta hai.
   - coturn (step 4.2) mein `external-ip=<PUBLIC_IP>/<PRIVATE_IP>` jodein. AWS par server ka apna IP private (`172.31.x.x`) hota hai. 1:1 call ko do alag mobile network se test karein.
   - Hostinger (hPanel) wale notes yahan lagu nahi hote.
3. Data:
   - **Raasta A**: tabhi jab purana AWS account dobara chalu ho. Purana server Start karein, phir step 5 ki backup script chalayein.
   - **Raasta B**: khaali database, agar data na mile.
   - Ye faisla user ka hai.
4. Elastic IP milte hi DNS badlein (step 2):
   - `bc.bharatseo.site` ka A record
   - `noidarankers.in`: A `@`, CNAME `www`, aur TXT `google-site-verification=…` (value guide mein hai)
   - Phir SSL (step 8)
5. Go-live checks (step 10) aur roz ka backup (step 11).
6. **Ruka hua feature kaam.** User ne 1 Nov ko dono ko haan kaha tha ("Ha pahla or 2 dono karo"). Abhi koi code nahi likha gaya:
   - a. Meetings page par **"ध्यान रहे" notice badalne ka box**. Settings `broadcast_notice_title` aur `broadcast_notice` hain, abhi poore org ke liye ek hi. Kaun badal sake (Admin aur/ya BC Supervisor) aur kya RO-wise alag ho, ye user se poochhein.
   - b. Bahar ke adhikari ke liye **guest link**. Sirf ek meeting ke liye, samay-seema ke saath, host band kar sake, rate limit ho, naam guest dikhe. Mic/camera sirf jab host use "मंच पर लाएँ".
7. **User ka faisla baaki**: 4 test BC jinki koi branch nahi hai, unhe delete karein ya kisi branch mein daalein:
   - id 1 Rahul `5800248000` (inactive)
   - id 2 Shivam `SHIVAM` (inactive)
   - id 3 Shivam `RAJU` (inactive)
   - id 4 RJ `00248` (active)

## Niyam jo pichhle kaam mein maane gaye

- Region isolation: BC Supervisor sirf apna RO dekhe aur chalaye. Kisi ko apne pad se zyada adhikar na mile.
- `meet.js`, `meet.css` ya `app.js` badlein to `?v=` number badhayein (abhi `v=11`).
- Production par file-by-file deploy karein, aur server par kiye gaye local badlav kabhi na mitayein.
- Secrets sirf `.env` ya settings table mein rahein, git mein kabhi nahi.
- User ne chat mein EricHost portal aur cPanel ke password bheje the; unhe dono badalne ko kaha gaya. Ye passwords kahin save nahi hain.
- Pichhle sandbox mein (agar naya session wahi sandbox paaye): purane aaPanel tak pahunchne ke scripts `/root/.cache/devenv/panel/` mein hain (`login.js`, `term2.js`). Inme passwords hain, kabhi commit na karein. Go-live tests: `/root/.cache/devenv/golive/test-nginx.sh` aur `test-backup.sh`.
