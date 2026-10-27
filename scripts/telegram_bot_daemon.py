#!/usr/bin/env python3
"""
scripts/telegram_bot_daemon.py
EduERP Telegram Server Health & Monitoring Daemon

Provides real-time interactive server telemetry and remote control via Telegram:
- /status or "📊 Server Status": CPU, RAM, Disk, Temp, Battery, Docker containers, Online students
- /backup or "💾 Instant Backup": Runs backup.sh and dispatches backup file
- /ping or "🏓 Ping": Instant health ping
- /help: Shows command guide and buttons

Strict Security: Responds ONLY to the authorized TELEGRAM_CHAT_ID in .env
"""

import os
import sys
import time
import json
import shutil
import logging
import subprocess
import urllib.request
import urllib.error
import urllib.parse
from datetime import datetime

# Setup logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s [%(levelname)s] %(message)s',
    handlers=[logging.StreamHandler(sys.stdout)]
)

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
PROJECT_DIR = os.path.dirname(SCRIPT_DIR)
ENV_FILE = os.path.join(PROJECT_DIR, '.env')

def load_env():
    env = {}
    if os.path.exists(ENV_FILE):
        with open(ENV_FILE, 'r', encoding='utf-8', errors='ignore') as f:
            for line in f:
                line = line.strip()
                if line and not line.startswith('#') and '=' in line:
                    k, v = line.split('=', 1)
                    env[k.strip()] = v.strip().strip('"').strip("'")
    return env

config = load_env()
BOT_TOKEN = config.get('TELEGRAM_BOT_TOKEN')
AUTHORIZED_CHAT_ID = config.get('TELEGRAM_CHAT_ID')

if not BOT_TOKEN or not AUTHORIZED_CHAT_ID:
    logging.error("TELEGRAM_BOT_TOKEN or TELEGRAM_CHAT_ID is missing from .env. Exiting.")
    sys.exit(1)

API_BASE = f"https://api.telegram.org/bot{BOT_TOKEN}"

# Custom persistent reply keyboard for 1-tap mobile operations
KEYBOARD_MARKUP = {
    "keyboard": [
        [{"text": "📊 Server Status"}, {"text": "💾 Instant Backup"}],
        [{"text": "🏓 Ping"}, {"text": "❓ Help"}]
    ],
    "resize_keyboard": True,
    "persistent": True
}

def send_telegram_message(chat_id, text, parse_mode="Markdown", reply_markup=None):
    try:
        payload = {
            "chat_id": chat_id,
            "text": text,
            "parse_mode": parse_mode
        }
        if reply_markup:
            payload["reply_markup"] = reply_markup
            
        data = json.dumps(payload).encode('utf-8')
        req = urllib.request.Request(
            f"{API_BASE}/sendMessage",
            data=data,
            headers={"Content-Type": "application/json"}
        )
        with urllib.request.urlopen(req, timeout=15) as resp:
            return json.loads(resp.read().decode('utf-8'))
    except Exception as e:
        logging.error(f"Failed to send message: {e}")
        return None

def get_server_status():
    """Compiles comprehensive server health metrics."""
    # 1. Uptime
    uptime = subprocess.getoutput("uptime -p").replace("up ", "")
    
    # 2. CPU Load
    try:
        load = os.getloadavg()
        load_str = f"{load[0]:.2f}, {load[1]:.2f}, {load[2]:.2f}"
    except Exception:
        load_str = "N/A"
        
    # 3. Memory
    mem_used_str = "N/A"
    try:
        with open('/proc/meminfo', 'r') as f:
            mem = dict(line.split(':', 1) for line in f if ':' in line)
        total_kb = int(mem.get('MemTotal', '0').strip().split()[0])
        avail_kb = int(mem.get('MemAvailable', '0').strip().split()[0])
        used_kb = total_kb - avail_kb
        total_mb = total_kb / 1024
        used_mb = used_kb / 1024
        pct = (used_kb / total_kb) * 100 if total_kb > 0 else 0
        mem_used_str = f"{used_mb:.0f} MB / {total_mb:.0f} MB ({pct:.1f}%)"
    except Exception as e:
        mem_used_str = str(e)

    # 4. Disk Space
    try:
        du = shutil.disk_usage('/')
        free_gb = du.free / (1024**3)
        total_gb = du.total / (1024**3)
        disk_pct = ((du.total - du.free) / du.total) * 100
        disk_str = f"{free_gb:.1f} GB free of {total_gb:.1f} GB ({disk_pct:.1f}% used)"
    except Exception:
        disk_str = "N/A"

    # 5. Temperature
    temp_str = "N/A"
    try:
        thermal_dir = "/sys/class/thermal"
        if os.path.exists(thermal_dir):
            for z in sorted(os.listdir(thermal_dir)):
                if z.startswith("thermal_zone"):
                    t_path = os.path.join(thermal_dir, z, "temp")
                    if os.path.exists(t_path):
                        with open(t_path, 'r') as tf:
                            raw = int(tf.read().strip())
                            deg = raw / 1000.0
                            if 20 <= deg <= 100:
                                temp_str = f"{deg:.1f}°C"
                                break
    except Exception:
        temp_str = "N/A"

    # 6. Power / Battery Status
    power_str = "AC Connected"
    try:
        bat_dir = "/sys/class/power_supply"
        if os.path.exists(bat_dir):
            bats = [b for b in os.listdir(bat_dir) if b.startswith("BAT")]
            if bats:
                b_path = os.path.join(bat_dir, bats[0])
                status = "Unknown"
                cap = ""
                if os.path.exists(os.path.join(b_path, "status")):
                    with open(os.path.join(b_path, "status")) as sf:
                        status = sf.read().strip()
                if os.path.exists(os.path.join(b_path, "capacity")):
                    with open(os.path.join(b_path, "capacity")) as cf:
                        cap = f" ({cf.read().strip()}%)"
                power_str = f"{status}{cap}"
    except Exception:
        pass

    # 7. Docker Containers
    containers_str = ""
    try:
        cmd = "docker ps --format '{{.Names}}|{{.Status}}'"
        out = subprocess.getoutput(cmd)
        if out.strip():
            lines = out.strip().split('\n')
            formatted = []
            for l in lines:
                if '|' in l:
                    name, stat = l.split('|', 1)
                    icon = "🟢" if "Up" in stat else "🔴"
                    # Shorten status
                    stat_short = stat.split('(')[0].strip()
                    formatted.append(f"{icon} `{name}`: {stat_short}")
            containers_str = "\n".join(formatted)
        else:
            containers_str = "⚠️ No running Docker containers found"
    except Exception as e:
        containers_str = f"Error querying Docker: {e}"

    # 8. Active Students Online Count
    online_students_str = "0"
    try:
        db_cmd = 'docker exec shreyansir_db mysql -u exam_user -pexam_password online_exam -s -N -e "SELECT COUNT(*) FROM user_sessions WHERE user_type = \'student\' AND is_active = 1 AND last_activity >= DATE_SUB(NOW(), INTERVAL 10 MINUTE);"'
        out_s = subprocess.getoutput(db_cmd)
        if out_s.strip().isdigit():
            online_students_str = out_s.strip()
    except Exception:
        online_students_str = "N/A"

    now_str = datetime.now().strftime("%Y-%m-%d %H:%M:%S IST")

    report = (
        f"🖥️ *EduERP Server Health Telemetry*\n"
        f"━━━━━━━━━━━━━━━━━━━━━━\n"
        f"⏱ *Uptime:* {uptime}\n"
        f"⚙️ *CPU Load:* `{load_str}`\n"
        f"🌡️ *Temperature:* `{temp_str}`\n"
        f"🔋 *Power/Battery:* {power_str}\n"
        f"🧠 *RAM Usage:* `{mem_used_str}`\n"
        f"💾 *Disk Space:* `{disk_str}`\n"
        f"👨‍🎓 *Online Students:* `{online_students_str} active`\n"
        f"━━━━━━━━━━━━━━━━━━━━━━\n"
        f"🐳 *Docker Services:*\n"
        f"{containers_str}\n"
        f"━━━━━━━━━━━━━━━━━━━━━━\n"
        f"🌐 *Domain:* `exam.shreyansir.in`\n"
        f"🕒 *Generated:* `{now_str}`"
    )
    return report

def run_backup_task(chat_id):
    """Executes the backup script and reports results."""
    send_telegram_message(chat_id, "⏳ *Starting on-demand backup...*\nDumping MariaDB and packaging user files...", reply_markup=KEYBOARD_MARKUP)
    
    backup_script = os.path.join(PROJECT_DIR, 'scripts', 'backup.sh')
    try:
        proc = subprocess.run(
            ['/bin/bash', backup_script],
            cwd=PROJECT_DIR,
            capture_output=True,
            text=True,
            timeout=120
        )
        if proc.returncode == 0:
            logging.info("Backup script completed successfully.")
        else:
            send_telegram_message(chat_id, f"⚠️ *Backup Warning:*\n```\n{proc.stderr[:400]}\n```", reply_markup=KEYBOARD_MARKUP)
    except Exception as e:
        send_telegram_message(chat_id, f"❌ *Backup Error:* {str(e)}", reply_markup=KEYBOARD_MARKUP)

def handle_message(message):
    chat = message.get('chat', {})
    chat_id = str(chat.get('id', ''))
    user_id = str(message.get('from', {}).get('id', ''))
    text = (message.get('text') or '').strip()

    # Strict Security Guard: Only the authorized user can command the server
    if chat_id != str(AUTHORIZED_CHAT_ID) and user_id != str(AUTHORIZED_CHAT_ID):
        logging.warning(f"Unauthorized command attempt from chat_id={chat_id}, user={message.get('from')}")
        send_telegram_message(chat_id, "⛔ *Access Denied:* You are not authorized to control this server.")
        return

    logging.info(f"Authorized command received: '{text}' from chat_id={chat_id}")

    cmd = text.lower()

    if cmd in ['/start', '/help', '❓ help']:
        help_msg = (
            "🎓 *EduERP Bot Control Console*\n\n"
            "Tap any button below or send a command:\n"
            "• *📊 Server Status* (`/status`) - Live CPU, RAM, Temp & Containers\n"
            "• *💾 Instant Backup* (`/backup`) - Create and download a fresh backup snapshot\n"
            "• *🏓 Ping* (`/ping`) - Test server response & connectivity\n"
            "• *❓ Help* (`/help`) - Show this command menu\n"
        )
        send_telegram_message(chat_id, help_msg, reply_markup=KEYBOARD_MARKUP)

    elif cmd in ['/status', '📊 server status', 'status']:
        status_report = get_server_status()
        send_telegram_message(chat_id, status_report, reply_markup=KEYBOARD_MARKUP)

    elif cmd in ['/backup', '💾 instant backup', 'backup']:
        run_backup_task(chat_id)

    elif cmd in ['/ping', '🏓 ping', 'ping']:
        now = datetime.now().strftime("%H:%M:%S")
        send_telegram_message(chat_id, f"🏓 *Pong!* Server is live and healthy.\n🕒 Host Time: `{now} IST`", reply_markup=KEYBOARD_MARKUP)

    else:
        send_telegram_message(
            chat_id,
            f"Unrecognized command: `{text}`\nTap a button below or send `/help` for options.",
            reply_markup=KEYBOARD_MARKUP
        )

def main():
    logging.info(f"EduERP Telegram Bot Daemon started. Listening for commands for Chat ID {AUTHORIZED_CHAT_ID}...")
    
    # Notify admin on startup
    try:
        startup_msg = "🟢 *EduERP Server Monitor Online*\nYour server is actively listening for Telegram status commands."
        send_telegram_message(AUTHORIZED_CHAT_ID, startup_msg, reply_markup=KEYBOARD_MARKUP)
    except Exception as e:
        logging.error(f"Could not send startup notice: {e}")

    offset = None
    while True:
        try:
            url = f"{API_BASE}/getUpdates?timeout=30"
            if offset:
                url += f"&offset={offset}"
                
            req = urllib.request.Request(url)
            with urllib.request.urlopen(req, timeout=45) as resp:
                data = json.loads(resp.read().decode('utf-8'))
                
            if data.get('ok') and data.get('result'):
                for item in data['result']:
                    offset = item['update_id'] + 1
                    if 'message' in item:
                        handle_message(item['message'])
        except urllib.error.URLError as e:
            logging.warning(f"Network glitch in long-polling: {e}. Retrying in 5s...")
            time.sleep(5)
        except Exception as e:
            logging.error(f"Unexpected error in daemon loop: {e}")
            time.sleep(5)

if __name__ == '__main__':
    main()
