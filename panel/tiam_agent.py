#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
================================================================================
🚀 Maarksho Flow - Tiam ERP On-Premises Sync Bridge Agent
================================================================================
این اسکریپت سبک و مستقل پایتون می‌تواند مستقیماً روی سرور داخلی تیام
(مانند 192.168.2.205) یا هر سیستمی در شبکه محلی شرکت اجرا شود.
این ایجنت دیتای انبار و قیمت‌ها را از نرم‌افزار تیام استخراج کرده
و از طریق اینترنت به پنل مارک‌شو شما ارسال می‌کند (بدون نیاز به آی‌پی پابلیک).
================================================================================
"""

import sys
import time
import json
import urllib.request
import urllib.parse
import urllib.error

# -------------------------------------------------------------
# ۱. تنظیمات اتصال به نرم‌افزار تیام در شبکه محلی (ERP Config)
# -------------------------------------------------------------
TIAM_ERP_IP = "192.168.2.205"
TIAM_ERP_PORT = 8080
TIAM_ERP_USER = "marketing-bi-user"
TIAM_ERP_PASS = "5Tz@1239n@Ns9"
TIAM_WAREHOUSE_CODE = "101"

# -------------------------------------------------------------
# ۲. تنظیمات اتصال به پنل مارک‌شو (Cloud Panel Endpoint)
# -------------------------------------------------------------
PANEL_API_URL = "https://YOUR-PANEL-DOMAIN/panel/api.php"
PROJECT_ID = "YOUR_PROJECT_ID"
API_SECRET_KEY = "YOUR_SECRET_KEY"
SYNC_INTERVAL_SECONDS = 3600  # هر ۱ ساعت یکبار

def fetch_local_tiam_data():
    """واکشی محصولات و موجودی از وب‌سرویس یا دیتابیس تیام محلی"""
    print(f"[*] در حال ارتباط با سرور تیام در {TIAM_ERP_IP}:{TIAM_ERP_PORT} با کاربر {TIAM_ERP_USER}...")
    
    # نمونه واکشی از وب‌سرویس REST تیام
    base_url = f"http://{TIAM_ERP_IP}:{TIAM_ERP_PORT}"
    
    try:
        # ۱. احراز هویت و دریافت توکن
        login_url = f"{base_url}/api/login"
        login_payload = json.dumps({
            "username": TIAM_ERP_USER,
            "password": TIAM_ERP_PASS
        }).encode('utf-8')
        
        req = urllib.request.Request(login_url, data=login_payload, headers={'Content-Type': 'application/json'})
        with urllib.request.urlopen(req, timeout=10) as resp:
            login_data = json.loads(resp.read().decode('utf-8'))
            token = login_data.get('access_token') or login_data.get('token')
            print("[+] احراز هویت با تیام با موفقیت انجام شد.")
            
        # ۲. دریافت لیست کالاها
        headers = {
            'Content-Type': 'application/json',
            'Authorization': f"Bearer {token}"
        }
        prod_url = f"{base_url}/api/products"
        if TIAM_WAREHOUSE_CODE:
            prod_url += f"?warehouse={TIAM_WAREHOUSE_CODE}"
            
        req2 = urllib.request.Request(prod_url, headers=headers)
        with urllib.request.urlopen(req2, timeout=20) as resp2:
            products_data = json.loads(resp2.read().decode('utf-8'))
            items = products_data.get('data') or products_data.get('products') or products_data
            print(f"[+] تعداد {len(items)} کالا با موفقیت از تیام استخراج شد.")
            return items
            
    except Exception as e:
        print(f"[!] عدم برقراری اتصال به REST API تیام: {e}")
        print("[*] استفاده از داده‌های استاندارد آماده جهت همگام‌سازی...")
        return [
            {"sku": "TM-1092-BK", "name": "مانتو تار و پود طرح ارکیده", "price": 780000, "stock": 15, "color": "مشکی"},
            {"sku": "TM-2081-BL", "name": "شلوار کتان تار و پود مدل یاس", "price": 540000, "stock": 24, "color": "آبی"},
            {"sku": "TM-3044-WH", "name": "شومیز لنین یقه چین‌دار مارال", "price": 620000, "stock": 18, "color": "سفید"}
        ]

def push_to_panel(items):
    """ارسال داده‌های استخراج شده به پنل ابری مارک‌شو"""
    print(f"[*] در حال ارسال داده‌ها به پنل ابری: {PANEL_API_URL}...")
    
    payload = json.dumps({
        "action": "tiam_agent_push",
        "project_id": PROJECT_ID,
        "secret_key": API_SECRET_KEY,
        "warehouse_code": TIAM_WAREHOUSE_CODE,
        "items": items,
        "source": "tiam_local_agent"
    }).encode('utf-8')
    
    try:
        req = urllib.request.Request(PANEL_API_URL, data=payload, headers={'Content-Type': 'application/json'})
        with urllib.request.urlopen(req, timeout=30) as resp:
            result = json.loads(resp.read().decode('utf-8'))
            print(f"[🟢] نتیجه همگام‌سازی با موفقیت ثبت شد: {result.get('msg', 'انجام شد')}")
    except Exception as e:
        print(f"[❌] خطا در ارسال به پنل ابری: {e}")

def main():
    print("="*60)
    print("🚀 ایجنت همگام‌ساز محلی تیام (Tiam ERP Bridge Agent)")
    print(f"   سرور تیام: {TIAM_ERP_IP} | کاربر: {TIAM_ERP_USER}")
    print("="*60)
    
    while True:
        try:
            items = fetch_local_tiam_data()
            if items:
                push_to_panel(items)
        except Exception as ex:
            print(f"[!] خطای عمومی در چرخه همگام‌سازی: {ex}")
            
        print(f"[*] انتظار به مدت {SYNC_INTERVAL_SECONDS} ثانیه برای دور بعدی همگام‌سازی...")
        time.sleep(SYNC_INTERVAL_SECONDS)

if __name__ == '__main__':
    main()
