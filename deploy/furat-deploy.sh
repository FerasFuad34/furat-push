#!/usr/bin/env bash
# =========================================================
# الفرات الحلوة — مزامنة التحديثات من GitHub إلى public_html
# الاستخدام (cron في Hostinger كل 10 دقائق):
#   bash /home/USERNAME/furat-src/deploy/furat-deploy.sh >> /home/USERNAME/furat-deploy.log 2>&1
# يطبّق الكود فقط — ملفات data/ الحيّة (المنتجات المحفوظة، العملاء،
# النسخ الاحتياطية، إعداد القاعدة) لا تُلمس إطلاقًا.
# =========================================================
set -u

# ⚙️ عدّل السطر الوحيد هذا عند أول تشغيل — رابط المستودع الخاص بكم
REPO_URL="${FURAT_REPO_URL:-https://github.com/USERNAME/furat-halwa.git}"

HOME_DIR="${FURAT_HOME:-$(getent passwd "$(id -un)" | cut -d: -f6)}"
REPO="$HOME_DIR/furat-src"
WEB="$HOME_DIR/public_html"
LOCKF="$HOME_DIR/.furat-deploy.lock"

cleanup(){ rm -rf "$LOCKF"; }
# قفل بسيط ضد تراكض نسختين
if ! mkdir "$LOCKF" 2>/dev/null; then echo "[$(date '+%F %T')] نسخة تشغيل أخرى قائمة — تخطّي"; exit 0; fi
trap cleanup EXIT

sync_now(){
  FRESH=0
  if [ ! -d "$REPO/.git" ]; then
    echo "[$(date '+%F %T')] أول مرة: استنساخ المستودع..."
    git clone -q "$REPO_URL" "$REPO" || { echo "فشل الاستنساخ — تحقق من الرابط"; exit 1; }
    FRESH=1
  fi
  cd "$REPO" || exit 1
  # أي تعارض محلي مهمل — السحبة نظيفة دائمًا
  git fetch -q origin || { echo "فشل الاتصال بـ GitHub (شبكة؟) — سأعيد المحاولة دوريةً"; exit 0; }
  CUR=$(git rev-parse HEAD 2>/dev/null); NEW=$(git rev-parse origin/main 2>/dev/null || git rev-parse origin/master 2>/dev/null)
  if [ "$FRESH" -eq 0 ] && [ -n "$NEW" ] && [ "$CUR" = "$NEW" ]; then exit 0; fi  # لا جديد — صفر كتابة على السيرفر
  git reset -q --hard "$NEW" 2>/dev/null
  echo "[$(date '+%F %T')] تحديث: ${CUR:0:7} → ${NEW:0:7}"
  # فحص أن المستودع لا يحمل بيانات حيّة (حارس مزدوج مع .gitignore)
  if git ls-files 'data/data.json' 'data/customers.json' | grep -q .; then
    echo "⚠️ تحذير: ملفات بيانات وُجدت في المستودع — لن تُنسخ لكن أزيلوها من git"; fi
  # فك كل ملفات المستودع فوق public_html (استخراج فقط — لا حذف لملفات السيرفر)
  TMP=$(mktemp -d)
  find . -path ./.git -prune -o -type f ! -name '.gitignore' ! -name '.gitattributes' -print0 \
    | tar -C "$REPO" --null -cf "$TMP/sync.tar" -T - 2>/dev/null
  tar -xf "$TMP/sync.tar" -C "$WEB" 2>/dev/null
  rc=$?
  rm -rf "$TMP"
  # المثبّت: بعد اكتمال التثبيت (قاعدة مضبوطة أو بيانات موجودة) لا يجوز أن يُعيد السحب إظهاره
  if [ -f "$WEB/data/db-config.json" ] || [ -f "$WEB/data/data.json" ]; then
    rm -f "$WEB/install.php"
  fi
  # صلاحيات مجلد الكتابة كما في التركيب اليدوي
  chmod 750 "$WEB/data" "$WEB/data/backups" 2>/dev/null
  [ $rc -eq 0 ] && echo "[$(date '+%F %T')] ✓ نُفِّذ التحديث بنجاح" || echo "[$(date '+%F %T')] ✗ خطأ في النسخ"
}
sync_now
