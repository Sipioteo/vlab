#!/usr/bin/env bash
#
# Visionary Lab — single entrypoint (SPEC §14).
# Avvia backend (PHP 8.1 / Slim 4) e frontend (React + Vite) in locale.
#
# Compatibile Linux, macOS e Windows Git Bash. Nessuna dipendenza esterna
# oltre a php / composer / node / npm (niente jq, docker, sudo).

set -euo pipefail

# ---------------------------------------------------------------- repo root --
# Risoluzione portabile della directory dello script: lo script funziona da
# qualsiasi CWD.
ROOT="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT"

BACKEND_DIR="$ROOT/backend"
FRONTEND_DIR="$ROOT/frontend"
RUN_DIR="$ROOT/.run"
LOG_DIR="$BACKEND_DIR/storage/logs"
BACKEND_PID_FILE="$RUN_DIR/backend.pid"
FRONTEND_PID_FILE="$RUN_DIR/frontend.pid"
BACKEND_LOG="$LOG_DIR/server.log"
FRONTEND_LOG="$LOG_DIR/frontend.log"

# ------------------------------------------------------------------ output ---
# Colori solo se stdout è un TTY e NO_COLOR non è impostata (SPEC §14.2 #16).
if [ -t 1 ] && [ -z "${NO_COLOR:-}" ]; then
  C_RESET=$'\033[0m'; C_RED=$'\033[31m'; C_GREEN=$'\033[32m'
  C_YELLOW=$'\033[33m'; C_BLUE=$'\033[34m'; C_BOLD=$'\033[1m'; C_DIM=$'\033[2m'
else
  C_RESET=''; C_RED=''; C_GREEN=''; C_YELLOW=''; C_BLUE=''; C_BOLD=''; C_DIM=''
fi

info() { printf '%s\n' "${C_BLUE}▸${C_RESET} $*"; }
ok()   { printf '%s\n' "${C_GREEN}✓${C_RESET} $*"; }
warn() { printf '%s\n' "${C_YELLOW}!${C_RESET} $*" >&2; }
err()  { printf '%s\n' "${C_RED}✗${C_RESET} $*" >&2; }
die()  { local code="$1"; shift; err "$*"; exit "$code"; }

# ---------------------------------------------------------------- OS detect --
UNAME_S="$(uname -s 2>/dev/null || echo unknown)"
case "$UNAME_S" in
  Linux*)                 OS=linux   ;;
  Darwin*)                OS=macos   ;;
  MINGW*|MSYS*|CYGWIN*)   OS=windows ;;
  *)                      OS=unknown ;;
esac

# ----------------------------------------------------------------- defaults --
BACKEND_PORT=8081
FRONTEND_PORT=8080
DO_INSTALL=1
DO_SEED=1
FRESH=0
LDAP_MODE_ARG=fake
COMMAND=""
DO_INSTALL_RUNTIME=0
INSTALL_RUNTIME_YES=0

NPM_BIN=""
NPX_BIN=""
COMPOSER_BIN=""

BACKEND_PID=""
FRONTEND_PID=""
CLEANED=0

# -------------------------------------------------------------------- usage --
usage() {
  cat <<EOF
${C_BOLD}Visionary Lab — run.sh${C_RESET}
Avvia in locale il backend (PHP/Slim) e il frontend (React/Vite).

${C_BOLD}Uso:${C_RESET}
  ./run.sh [comando] [opzioni]

${C_BOLD}Comandi:${C_RESET}
  (nessuno) | start   Flusso completo: prerequisiti → dipendenze → DB →
                      migrazioni → seed → avvio di entrambi i server
  install             Installa solo le dipendenze (composer + npm)
  migrate             Esegue solo le migrazioni
  seed                Esegue solo i seeder
  fresh               Ricrea il database da zero (migrate:fresh + seed --demo)
                      e poi avvia i server
  backend             Avvia solo il backend (porta $BACKEND_PORT)
  frontend            Avvia solo il frontend (porta $FRONTEND_PORT)
  test                Esegue PHPUnit e poi Vitest; esce != 0 se uno fallisce
  stop                Termina i processi registrati nei file PID
  install-runtime     Tenta di installare i runtime di sistema mancanti (PHP,
                      Composer, Node.js/npm) usando il package manager del SO
  help                Questo messaggio

${C_BOLD}Opzioni:${C_RESET}
  --backend-port N    Porta del backend (default 8081; aggiorna anche il proxy
                      Vite tramite VITE_API_TARGET)
  --frontend-port N   Porta del frontend (default 8080)
  --no-install        Salta l'installazione delle dipendenze
  --no-seed           Salta il seeding
  --fresh             Come il comando "fresh" (DB ricreato + dati demo)
  --real-ldap         Usa LDAP_MODE=real invece del default fake
  --install-runtime   Come il comando "install-runtime" (combinabile con
                      qualunque altro comando: prova a installare i runtime
                      mancanti prima del controllo prerequisiti)
  --install-runtime-yes  Come --install-runtime ma senza chiedere conferma
                      (richiesto se stdin non è un terminale)
  -h, --help          Questo messaggio

${C_BOLD}Esempi:${C_RESET}
  ./run.sh                          # avvio normale, i dati sopravvivono
  ./run.sh --fresh                  # DB ricreato da zero + 25 ordini demo
  ./run.sh --backend-port 9091      # porte alternative
  ./run.sh test                     # solo le suite di test
  ./run.sh stop                     # ferma i server avviati in background
  ./run.sh install-runtime          # installa PHP/Composer/Node mancanti
  ./run.sh install-runtime --install-runtime-yes   # senza conferma interattiva

${C_BOLD}Codici di uscita:${C_RESET}
  0 ok  ·  1 prerequisiti/porte  ·  2 installazione  ·  3 migrazioni/seed
  4 backend non diventato healthy
  (install-runtime usa 0 se tutto è a posto o installato con successo, 1 se al
  termine mancano ancora prerequisiti: vedi il messaggio del controllo finale)
EOF
}

# ------------------------------------------------------------ argument parse --
parse_args() {
  while [ $# -gt 0 ]; do
    case "$1" in
      start|install|migrate|seed|fresh|backend|frontend|test|stop|install-runtime|help)
        if [ -z "$COMMAND" ]; then
          COMMAND="$1"
        else
          die 1 "Comando duplicato: '$1' (già impostato '$COMMAND')."
        fi
        ;;
      --backend-port)
        [ $# -ge 2 ] || die 1 "--backend-port richiede un numero di porta."
        BACKEND_PORT="$2"; shift
        ;;
      --backend-port=*) BACKEND_PORT="${1#*=}" ;;
      --frontend-port)
        [ $# -ge 2 ] || die 1 "--frontend-port richiede un numero di porta."
        FRONTEND_PORT="$2"; shift
        ;;
      --frontend-port=*) FRONTEND_PORT="${1#*=}" ;;
      --no-install) DO_INSTALL=0 ;;
      --no-seed)    DO_SEED=0 ;;
      --fresh)      FRESH=1 ;;
      --real-ldap)  LDAP_MODE_ARG=real ;;
      --install-runtime)     DO_INSTALL_RUNTIME=1 ;;
      --install-runtime-yes) DO_INSTALL_RUNTIME=1; INSTALL_RUNTIME_YES=1 ;;
      -h|--help)    COMMAND=help ;;
      *)
        err "Opzione sconosciuta: '$1'"
        echo ""
        usage
        exit 1
        ;;
    esac
    shift
  done
  [ -n "$COMMAND" ] || COMMAND=start
  if [ "$COMMAND" = "fresh" ]; then FRESH=1; fi
  if [ "$COMMAND" = "install-runtime" ]; then DO_INSTALL_RUNTIME=1; fi
  case "$BACKEND_PORT" in ''|*[!0-9]*) die 1 "Porta backend non valida: '$BACKEND_PORT'." ;; esac
  case "$FRONTEND_PORT" in ''|*[!0-9]*) die 1 "Porta frontend non valida: '$FRONTEND_PORT'." ;; esac
  [ "$BACKEND_PORT" != "$FRONTEND_PORT" ] || die 1 "Le porte di backend e frontend devono essere diverse."
  return 0
}

# ------------------------------------------------------------- prerequisites --
install_hint() {
  # $1 = nome tool
  case "$OS" in
    linux)   printf 'installalo con il package manager della distro (es. apt install %s / dnf install %s)' "$1" "$1" ;;
    macos)   printf 'installalo con Homebrew: brew install %s' "$1" ;;
    windows) printf 'installalo e assicurati che sia nel PATH di Git Bash (es. scoop install %s oppure winget)' "$1" ;;
    *)       printf 'installalo e assicurati che sia nel PATH' ;;
  esac
}

require_prereqs() {
  local missing=0

  # --- php ---
  if ! command -v php >/dev/null 2>&1; then
    err "PHP non trovato nel PATH."
    err "  → Serve PHP 8.1 o superiore. Su $OS: $(install_hint php)."
    missing=1
  elif ! php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' >/dev/null 2>&1; then
    err "PHP troppo vecchio: $(php -r 'echo PHP_VERSION;' 2>/dev/null). Serve almeno 8.1."
    err "  → Aggiorna PHP. Su $OS: $(install_hint php)."
    missing=1
  else
    ok "php $(php -r 'echo PHP_VERSION;')"
  fi

  # --- composer ---
  COMPOSER_BIN="$(command -v composer 2>/dev/null || command -v composer.phar 2>/dev/null || true)"
  if [ -z "$COMPOSER_BIN" ]; then
    err "Composer non trovato nel PATH."
    err "  → Installalo da https://getcomposer.org/download/ (su $OS: $(install_hint composer))."
    missing=1
  else
    ok "composer ($COMPOSER_BIN)"
  fi

  # --- node ---
  if ! command -v node >/dev/null 2>&1; then
    err "Node.js non trovato nel PATH."
    err "  → Serve Node 18 o superiore. Su $OS: $(install_hint node) (oppure usa nvm)."
    missing=1
  else
    local node_major
    node_major="$(node -p 'process.versions.node.split(".")[0]' 2>/dev/null || echo 0)"
    if [ "$node_major" -lt 18 ] 2>/dev/null; then
      err "Node.js troppo vecchio: $(node -v). Serve almeno la versione 18."
      err "  → Aggiorna Node. Su $OS: $(install_hint node) (oppure nvm install 20)."
      missing=1
    else
      ok "node $(node -v)"
    fi
  fi

  # --- npm (su Windows spesso è npm.cmd) ---
  NPM_BIN="$(command -v npm 2>/dev/null || command -v npm.cmd 2>/dev/null || true)"
  if [ -z "$NPM_BIN" ]; then
    err "npm non trovato nel PATH (cercato anche 'npm.cmd' per Git Bash)."
    err "  → npm viene installato insieme a Node.js. Su $OS: $(install_hint node)."
    missing=1
  else
    ok "npm $("$NPM_BIN" -v 2>/dev/null || echo '?') ($NPM_BIN)"
  fi
  NPX_BIN="$(command -v npx 2>/dev/null || command -v npx.cmd 2>/dev/null || true)"

  [ "$missing" -eq 0 ] || die 1 "Prerequisiti mancanti: correggi quanto sopra e riprova."

  # --- estensioni PHP ---
  local ext missing_ext=""
  for ext in pdo pdo_sqlite mbstring json openssl; do
    if ! php -r "exit(extension_loaded('$ext') ? 0 : 1);" >/dev/null 2>&1; then
      missing_ext="$missing_ext $ext"
    fi
  done
  if [ -n "$missing_ext" ]; then
    err "Estensioni PHP mancanti:$missing_ext"
    err "  → Abilitale in php.ini (cerca ';extension=...' e togli il punto e virgola)."
    err "  → php.ini in uso: $(php -r 'echo php_ini_loaded_file() ?: "(nessuno)";')"
    exit 1
  fi
  ok "estensioni PHP: pdo, pdo_sqlite, mbstring, json, openssl"

  if ! php -r "exit(extension_loaded('ldap') ? 0 : 1);" >/dev/null 2>&1; then
    if [ "$LDAP_MODE_ARG" = "real" ]; then
      die 1 "Estensione PHP 'ldap' assente ma è stato richiesto --real-ldap. Abilita extension=ldap in php.ini."
    fi
    warn "Estensione PHP 'ldap' assente: ok perché LDAP_MODE=fake (serve solo per --real-ldap)."
  fi
}

# ------------------------------------------------------------ install-runtime --
# Individua il package manager di sistema "migliore disponibile" e imposta
# PKG_MGR (vuoto se nessuno adatto), SUDO_PREFIX ("sudo " o "") e, in caso di
# fallimento, PM_ERROR con il motivo (da mostrare all'utente).
detect_pkg_manager() {
  PKG_MGR=""
  SUDO_PREFIX=""
  PM_ERROR=""
  case "$OS" in
    linux)
      local mgr
      for mgr in apt-get dnf pacman zypper; do
        if command -v "$mgr" >/dev/null 2>&1; then PKG_MGR="$mgr"; break; fi
      done
      if [ -z "$PKG_MGR" ]; then
        PM_ERROR="nessun package manager supportato trovato (atteso apt-get, dnf, pacman o zypper)"
      elif [ "$(id -u 2>/dev/null || echo 1000)" -ne 0 ]; then
        if command -v sudo >/dev/null 2>&1; then
          SUDO_PREFIX="sudo "
        else
          PM_ERROR="servono privilegi di root oppure 'sudo' (non trovato) per usare $PKG_MGR"
          PKG_MGR=""
        fi
      fi
      ;;
    macos)
      if command -v brew >/dev/null 2>&1; then
        PKG_MGR="brew"
      else
        PM_ERROR="Homebrew non trovato: installalo da https://brew.sh"
      fi
      ;;
    windows)
      local mgr
      for mgr in winget scoop choco; do
        if command -v "$mgr" >/dev/null 2>&1; then PKG_MGR="$mgr"; break; fi
      done
      [ -n "$PKG_MGR" ] || PM_ERROR="nessun package manager supportato trovato (atteso winget, scoop o choco)"
      ;;
    *)
      PM_ERROR="sistema operativo non riconosciuto: installazione automatica non supportata"
      ;;
  esac
}

# Nomi pacchetto per package manager: alcune distro Linux separano le
# estensioni PHP in pacchetti a parte, macOS/Windows le includono nel
# pacchetto principale.
pkg_names_for() {
  local mgr="$1" what="$2"
  case "${mgr}:${what}" in
    apt-get:php)      echo "php-cli php-sqlite3 php-mbstring php-xml php-curl" ;;
    dnf:php)          echo "php-cli php-pdo php-mbstring php-xml php-curl php-json" ;;
    pacman:php)       echo "php php-sqlite" ;;
    zypper:php)       echo "php8 php8-cli php8-sqlite php8-mbstring php8-xml php8-curl" ;;
    brew:php)         echo "php" ;;
    winget:php)       echo "PHP.PHP.8.3" ;;
    scoop:php)        echo "php" ;;
    choco:php)        echo "php" ;;
    apt-get:composer) echo "composer" ;;
    dnf:composer)     echo "composer" ;;
    pacman:composer)  echo "composer" ;;
    zypper:composer)  echo "composer" ;;
    brew:composer)    echo "composer" ;;
    winget:composer)  echo "Composer.Composer" ;;
    scoop:composer)   echo "composer" ;;
    choco:composer)   echo "composer" ;;
    apt-get:node)     echo "nodejs npm" ;;
    dnf:node)         echo "nodejs npm" ;;
    pacman:node)      echo "nodejs npm" ;;
    zypper:node)      echo "nodejs npm" ;;
    brew:node)        echo "node" ;;
    winget:node)      echo "OpenJS.NodeJS.LTS" ;;
    scoop:node)       echo "nodejs-lts" ;;
    choco:node)       echo "nodejs-lts" ;;
    *) echo "" ;;
  esac
}

# Stampa il comando ESATTO che pm_install_run eseguirebbe per $1 (pacchetti
# separati da spazio), da mostrare all'utente prima della conferma.
pm_install_line() {
  local pkgs="$1" pkg out
  case "$PKG_MGR" in
    apt-get) printf '%sapt-get update && %sapt-get install -y %s' "$SUDO_PREFIX" "$SUDO_PREFIX" "$pkgs" ;;
    dnf)     printf '%sdnf install -y %s' "$SUDO_PREFIX" "$pkgs" ;;
    pacman)  printf '%spacman -Sy --noconfirm %s' "$SUDO_PREFIX" "$pkgs" ;;
    zypper)  printf '%szypper --non-interactive install %s' "$SUDO_PREFIX" "$pkgs" ;;
    brew)    printf 'brew install %s' "$pkgs" ;;
    winget)
      out=""
      for pkg in $pkgs; do
        out="${out}winget install --id $pkg -e --silent --accept-package-agreements --accept-source-agreements; "
      done
      printf '%s' "$out"
      ;;
    scoop)   printf 'scoop install %s' "$pkgs" ;;
    choco)   printf 'choco install -y %s' "$pkgs" ;;
  esac
}

# Esegue davvero l'installazione di $1 (pacchetti separati da spazio) con
# PKG_MGR/SUDO_PREFIX già impostati da detect_pkg_manager. Ritorna 0/1.
pm_install_run() {
  local pkgs="$1"
  [ -n "$pkgs" ] || return 1
  case "$PKG_MGR" in
    apt-get) eval "${SUDO_PREFIX}apt-get update && ${SUDO_PREFIX}apt-get install -y $pkgs" ;;
    dnf)     eval "${SUDO_PREFIX}dnf install -y $pkgs" ;;
    pacman)  eval "${SUDO_PREFIX}pacman -Sy --noconfirm $pkgs" ;;
    zypper)  eval "${SUDO_PREFIX}zypper --non-interactive install $pkgs" ;;
    brew)    eval "brew install $pkgs" ;;
    winget)
      local pkg rc=0
      for pkg in $pkgs; do
        winget install --id "$pkg" -e --silent --accept-package-agreements --accept-source-agreements || rc=1
      done
      return "$rc"
      ;;
    scoop)   eval "scoop install $pkgs" ;;
    choco)   eval "choco install -y $pkgs" ;;
    *) return 1 ;;
  esac
}

# Fallback ufficiale per Composer quando il package manager non lo offre
# (o non è disponibile): segue esattamente la procedura documentata su
# https://getcomposer.org/download/, con verifica della firma SHA-384
# scaricata da composer.github.io/installer.sig PRIMA di eseguire lo script.
# Non esegue mai `curl | php` diretto.
install_composer_fallback() {
  if ! command -v curl >/dev/null 2>&1; then
    err "  → curl non disponibile: impossibile scaricare l'installer ufficiale di Composer."
    return 1
  fi
  if ! command -v php >/dev/null 2>&1; then
    err "  → PHP non disponibile: impossibile eseguire l'installer di Composer."
    return 1
  fi

  local tmp expected actual destdir
  tmp="$(mktemp -d 2>/dev/null || true)"
  [ -n "$tmp" ] || tmp="$RUN_DIR/tmp.install-runtime.$$"
  mkdir -p "$tmp"

  info "  scarico l'installer ufficiale di Composer…"
  if ! curl -fsSL https://getcomposer.org/installer -o "$tmp/composer-setup.php" 2>/dev/null; then
    err "  → download di composer-setup.php fallito."
    rm -rf "$tmp"
    return 1
  fi

  expected="$(curl -fsSL https://composer.github.io/installer.sig 2>/dev/null || true)"
  if [ -z "$expected" ]; then
    err "  → impossibile scaricare la firma da composer.github.io/installer.sig."
    rm -rf "$tmp"
    return 1
  fi
  actual="$(php -r "echo hash_file('sha384', '$tmp/composer-setup.php');" 2>/dev/null || true)"
  if [ -z "$actual" ] || [ "$expected" != "$actual" ]; then
    err "  → verifica della firma SHA-384 di Composer fallita (atteso $expected, ottenuto ${actual:-<vuoto>}): installer scartato."
    rm -rf "$tmp"
    return 1
  fi
  ok "  firma SHA-384 dell'installer Composer verificata"

  if [ -d "$HOME/.local/bin" ] && [ -w "$HOME/.local/bin" ]; then
    destdir="$HOME/.local/bin"
  elif mkdir -p "$HOME/.local/bin" 2>/dev/null; then
    destdir="$HOME/.local/bin"
  else
    destdir="$ROOT/bin"
    mkdir -p "$destdir"
  fi

  if ! ( cd "$tmp" && php composer-setup.php --install-dir="$destdir" --filename=composer >/dev/null 2>&1 ); then
    err "  → esecuzione di composer-setup.php fallita."
    rm -rf "$tmp"
    return 1
  fi
  chmod +x "$destdir/composer" 2>/dev/null || true
  rm -rf "$tmp"

  case ":$PATH:" in
    *":$destdir:"*) : ;;
    *)
      PATH="$destdir:$PATH"; export PATH
      warn "  → Composer installato in $destdir (non era nel PATH): aggiunto solo per questa sessione."
      warn "     Per renderlo permanente aggiungi 'export PATH=\"$destdir:\$PATH\"' al tuo profilo di shell."
      ;;
  esac
  hash -r 2>/dev/null || true
  ok "  composer installato in $destdir/composer"
  return 0
}

# Tenta di installare i runtime di sistema mancanti (PHP + estensioni,
# Composer, Node.js/npm). Non fallisce mai lo script: riporta l'esito per
# ciascun elemento e lascia che il successivo require_prereqs decida se
# mancano ancora prerequisiti.
install_runtime() {
  printf '\n%s\n' "${C_BOLD}install-runtime: verifica dei runtime mancanti…${C_RESET}"

  local need_php=0 need_composer=0 need_node=0 need_ext=""

  if ! command -v php >/dev/null 2>&1; then
    need_php=1
  elif ! php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' >/dev/null 2>&1; then
    need_php=1
  fi

  local cb; cb="$(command -v composer 2>/dev/null || command -v composer.phar 2>/dev/null || true)"
  [ -n "$cb" ] || need_composer=1

  if ! command -v node >/dev/null 2>&1; then
    need_node=1
  else
    local node_major
    node_major="$(node -p 'process.versions.node.split(".")[0]' 2>/dev/null || echo 0)"
    if [ "$node_major" -lt 18 ] 2>/dev/null; then need_node=1; fi
  fi

  if [ "$need_php" -eq 0 ]; then
    local ext
    for ext in pdo pdo_sqlite mbstring json openssl; do
      php -r "exit(extension_loaded('$ext') ? 0 : 1);" >/dev/null 2>&1 || need_ext="$need_ext $ext"
    done
  fi

  if [ "$need_php" -eq 0 ] && [ "$need_composer" -eq 0 ] && [ "$need_node" -eq 0 ] && [ -z "$need_ext" ]; then
    ok "runtime già presenti, nulla da installare"
    return 0
  fi

  detect_pkg_manager

  local plan_txt="" n_planned=0
  local php_pkgs="" node_pkgs="" composer_pkgs=""

  if [ "$need_php" -eq 1 ] || [ -n "$need_ext" ]; then
    if [ -n "$PKG_MGR" ]; then
      php_pkgs="$(pkg_names_for "$PKG_MGR" php)"
      plan_txt="${plan_txt}  - PHP (+ estensioni): $(pm_install_line "$php_pkgs")
"
      n_planned=$((n_planned + 1))
    else
      err "PHP: impossibile pianificare l'installazione (${PM_ERROR})."
    fi
  fi

  if [ "$need_composer" -eq 1 ]; then
    if [ -n "$PKG_MGR" ]; then
      composer_pkgs="$(pkg_names_for "$PKG_MGR" composer)"
      plan_txt="${plan_txt}  - Composer: $(pm_install_line "$composer_pkgs")
    (se il pacchetto non è disponibile: installer ufficiale getcomposer.org
    con verifica della firma SHA-384, mai 'curl | php' diretto)
"
    else
      plan_txt="${plan_txt}  - Composer: installer ufficiale getcomposer.org (curl + verifica
    della firma SHA-384 da composer.github.io/installer.sig)
"
    fi
    n_planned=$((n_planned + 1))
  fi

  if [ "$need_node" -eq 1 ]; then
    if [ -n "$PKG_MGR" ]; then
      node_pkgs="$(pkg_names_for "$PKG_MGR" node)"
      plan_txt="${plan_txt}  - Node.js/npm: $(pm_install_line "$node_pkgs")
"
      n_planned=$((n_planned + 1))
    else
      err "Node.js: impossibile pianificare l'installazione (${PM_ERROR})."
    fi
  fi

  if [ "$n_planned" -eq 0 ]; then
    err "Nessuna azione di installazione disponibile su questo sistema."
    return 1
  fi

  printf '\n%s\n%s\n' "Verranno eseguiti i seguenti comandi:" "$plan_txt"

  if [ "$INSTALL_RUNTIME_YES" -eq 1 ]; then
    info "conferma automatica (--install-runtime-yes)"
  elif [ ! -t 0 ]; then
    err "Input non interattivo: nessuna installazione verrà eseguita senza conferma."
    err "  → Esegui ./run.sh in un terminale interattivo oppure aggiungi --install-runtime-yes."
    return 1
  else
    printf '%s' "Procedere con l'installazione? [y/N] "
    local risposta=""
    read -r risposta || risposta=""
    case "$risposta" in
      y|Y|s|S) : ;;
      *) info "Installazione annullata dall'utente."; return 1 ;;
    esac
  fi

  local overall_status=0

  if [ "$need_php" -eq 1 ] || [ -n "$need_ext" ]; then
    if [ -n "$php_pkgs" ]; then
      info "installo PHP…"
      if pm_install_run "$php_pkgs"; then
        hash -r 2>/dev/null || true
        if command -v php >/dev/null 2>&1 && php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' >/dev/null 2>&1; then
          ok "PHP: installato ($(php -r 'echo PHP_VERSION;'))"
        else
          err "PHP: installato ma ancora non utilizzabile (versione insufficiente o comando assente)."
          overall_status=1
        fi
      else
        err "PHP: fallito."
        overall_status=1
      fi
    else
      err "PHP: saltato (nessun package manager disponibile)."
      overall_status=1
    fi
  fi

  if [ "$need_composer" -eq 1 ]; then
    local composer_done=0
    if [ -n "$composer_pkgs" ]; then
      info "installo Composer (via $PKG_MGR)…"
      if pm_install_run "$composer_pkgs"; then
        hash -r 2>/dev/null || true
        if command -v composer >/dev/null 2>&1; then
          ok "Composer: installato (via $PKG_MGR)"
          composer_done=1
        fi
      fi
    fi
    if [ "$composer_done" -eq 0 ]; then
      info "installo Composer (installer ufficiale)…"
      if install_composer_fallback; then
        composer_done=1
      fi
    fi
    if [ "$composer_done" -eq 0 ]; then
      err "Composer: fallito."
      overall_status=1
    fi
  fi

  if [ "$need_node" -eq 1 ]; then
    if [ -n "$node_pkgs" ]; then
      info "installo Node.js…"
      if pm_install_run "$node_pkgs"; then
        hash -r 2>/dev/null || true
        local nm
        nm="$(node -p 'process.versions.node.split(".")[0]' 2>/dev/null || echo 0)"
        if command -v node >/dev/null 2>&1 && [ "$nm" -ge 18 ] 2>/dev/null; then
          ok "Node.js: installato ($(node -v))"
        else
          err "Node.js: installato ma la versione resta inferiore alla 18 (repository della distro non aggiornato)."
          err "  → Usa nvm per una versione più recente: https://github.com/nvm-sh/nvm (nvm install 20)."
          overall_status=1
        fi
      else
        err "Node.js: fallito."
        overall_status=1
      fi
    else
      err "Node.js: saltato (nessun package manager disponibile)."
      overall_status=1
    fi
  fi

  return "$overall_status"
}

# ------------------------------------------------------------------- .env ----
random_hex_24() {
  if command -v openssl >/dev/null 2>&1; then
    openssl rand -hex 24 2>/dev/null && return 0
  fi
  if [ -r /dev/urandom ] && command -v od >/dev/null 2>&1; then
    head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n' && return 0
  fi
  # Ultima spiaggia: PHP è già un prerequisito.
  php -r 'echo bin2hex(random_bytes(24));'
}

bootstrap_env() {
  if [ -f "$BACKEND_DIR/.env" ]; then
    ok ".env già presente (non viene mai sovrascritto)"
    return 0
  fi
  [ -f "$BACKEND_DIR/.env.example" ] || die 1 "Manca backend/.env.example: impossibile creare backend/.env."
  cp "$BACKEND_DIR/.env.example" "$BACKEND_DIR/.env"

  local secret
  secret="$(random_hex_24)"
  # sed -i differisce fra GNU e BSD: si usa un file temporaneo, sempre portabile.
  local tmp="$BACKEND_DIR/.env.tmp.$$"
  JWT_SECRET_VALUE="$secret" php -r '
    $file = $argv[1];
    $tmp = $argv[2];
    $secret = getenv("JWT_SECRET_VALUE");
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    $found = false;
    foreach ($lines as $i => $line) {
      if (str_starts_with($line, "JWT_SECRET=")) { $lines[$i] = "JWT_SECRET=" . $secret; $found = true; }
    }
    if (!$found) { $lines[] = "JWT_SECRET=" . $secret; }
    file_put_contents($tmp, implode("\n", $lines) . "\n");
  ' "$BACKEND_DIR/.env" "$tmp"
  mv "$tmp" "$BACKEND_DIR/.env"
  ok "creato backend/.env da .env.example con JWT_SECRET casuale (48 hex)"
}

# ---------------------------------------------------------------- installs ---
newer_than() { # newer_than FILE DIR_OR_FILE → 0 se FILE è più recente
  [ -e "$1" ] || return 1
  [ -e "$2" ] || return 0
  [ "$1" -nt "$2" ]
}

install_backend_deps() {
  if [ ! -f "$BACKEND_DIR/vendor/autoload.php" ] || newer_than "$BACKEND_DIR/composer.lock" "$BACKEND_DIR/vendor"; then
    info "composer install…"
    ( cd "$BACKEND_DIR" && "$COMPOSER_BIN" install --no-interaction --prefer-dist ) \
      || die 2 "composer install fallito."
    ok "dipendenze backend installate"
  else
    ok "dipendenze backend già aggiornate (vendor/)"
  fi
}

install_frontend_deps() {
  if [ ! -d "$FRONTEND_DIR/node_modules" ] || newer_than "$FRONTEND_DIR/package-lock.json" "$FRONTEND_DIR/node_modules"; then
    if [ -f "$FRONTEND_DIR/package-lock.json" ]; then
      info "npm ci…"
      ( cd "$FRONTEND_DIR" && "$NPM_BIN" ci ) || {
        warn "npm ci fallito, riprovo con npm install…"
        ( cd "$FRONTEND_DIR" && "$NPM_BIN" install ) || die 2 "npm install fallito."
      }
    else
      info "npm install…"
      ( cd "$FRONTEND_DIR" && "$NPM_BIN" install ) || die 2 "npm install fallito."
    fi
    ok "dipendenze frontend installate"
  else
    ok "dipendenze frontend già aggiornate (node_modules/)"
  fi
}

do_install() {
  if [ "$DO_INSTALL" -eq 0 ]; then
    info "installazione dipendenze saltata (--no-install)"
    return 0
  fi
  install_backend_deps
  install_frontend_deps
}

# ---------------------------------------------------------------- database ---
sqlite_path() {
  # Legge DB_DRIVER/DB_DATABASE effettivi dalla configurazione del backend.
  ( cd "$BACKEND_DIR" && php -r '
      $c = require "config/database.php";
      echo ($c["driver"] ?? "") === "sqlite" ? (string) ($c["database"] ?? "") : "";
    ' 2>/dev/null ) || true
}

db_driver() {
  ( cd "$BACKEND_DIR" && php -r '$c = require "config/database.php"; echo (string) ($c["driver"] ?? "sqlite");' 2>/dev/null ) || echo sqlite
}

prepare_db() {
  mkdir -p "$BACKEND_DIR/database"
  local db; db="$(sqlite_path)"
  if [ -n "$db" ] && [ "$db" != ":memory:" ]; then
    if [ "$FRESH" -eq 1 ] && [ -f "$db" ]; then
      rm -f "$db" "$db-journal" "$db-wal" "$db-shm"
      info "database SQLite eliminato (--fresh)"
    fi
    mkdir -p "$(dirname "$db")"
    [ -f "$db" ] || touch "$db"
  fi
}

run_migrations() {
  if [ "$FRESH" -eq 1 ]; then
    info "migrate:fresh…"
    ( cd "$BACKEND_DIR" && php bin/console migrate:fresh ) || die 3 "migrate:fresh fallito."
  else
    info "migrate…"
    # `migrate` è idempotente: le migrazioni già applicate vengono saltate e i
    # dati esistenti sopravvivono ai riavvii.
    ( cd "$BACKEND_DIR" && php bin/console migrate ) || die 3 "migrate fallito."
  fi
  ok "migrazioni applicate"
}

run_seed() {
  if [ "$DO_SEED" -eq 0 ]; then
    info "seeding saltato (--no-seed)"
    return 0
  fi
  [ -f "$ROOT/data/catalog.json" ] || warn "data/catalog.json assente: il catalogo non verrà importato."
  if [ "$FRESH" -eq 1 ]; then
    info "db:seed --demo…"
    ( cd "$BACKEND_DIR" && php bin/console db:seed --demo ) || die 3 "db:seed --demo fallito."
  else
    info "db:seed…"
    # I seeder sono idempotenti (upsert): rieseguirli non duplica nulla.
    ( cd "$BACKEND_DIR" && php bin/console db:seed ) || die 3 "db:seed fallito."
  fi
  ok "seed completato"
}

# ------------------------------------------------------------------- ports ---
port_in_use() { # 0 = occupata
  local port="$1"
  if command -v lsof >/dev/null 2>&1; then
    lsof -nP -iTCP:"$port" -sTCP:LISTEN >/dev/null 2>&1 && return 0
  elif command -v ss >/dev/null 2>&1; then
    ss -ltn 2>/dev/null | grep -q "[:.]${port}[[:space:]]" && return 0
  elif command -v netstat >/dev/null 2>&1; then
    netstat -an 2>/dev/null | grep -i 'listen' | grep -q "[:.]${port}[[:space:]]" && return 0
  fi
  # Fallback universale (funziona anche in Git Bash): probe TCP di bash.
  if (exec 3<>/dev/tcp/127.0.0.1/"$port") 2>/dev/null; then
    exec 3>&- 2>/dev/null || true
    return 0
  fi
  return 1
}

require_free_port() { # $1 = porta, $2 = etichetta, $3 = flag da usare per cambiarla
  if port_in_use "$1"; then
    err "La porta $1 ($2) è già occupata."
    err "  → Chiudi il processo che la usa oppure riavvia con: ./run.sh $3 <altra-porta>"
    exit 1
  fi
}

# ------------------------------------------------------------------ process --
read_pid_file() { [ -f "$1" ] && head -n 1 "$1" 2>/dev/null | tr -d '[:space:]' || true; }

pid_alive() { [ -n "${1:-}" ] && kill -0 "$1" 2>/dev/null; }

# Termina un PID e TUTTI i suoi discendenti.
#
# Serve davvero: `npm run dev` produce una catena profonda
#   subshell → npm → sh -c vite → node vite → esbuild
# e fermarsi al primo livello lascerebbe vite orfano sulla porta 8080.
# L'albero viene ricostruito da un unico snapshot di `ps` (disponibile su
# Linux, macOS e Git Bash): niente pkill, niente assunzioni sui process group.
kill_pid_tree() {
  local pid="${1:-}"
  [ -n "$pid" ] || return 0

  local snapshot=""
  if command -v ps >/dev/null 2>&1; then
    snapshot="$(ps -A -o pid= -o ppid= 2>/dev/null || true)"
  fi

  # Visita in ampiezza: targets = pid + tutti i discendenti.
  local targets="$pid"
  if [ -n "$snapshot" ]; then
    local frontier="$pid" next kids p depth=0
    while [ -n "$frontier" ] && [ "$depth" -lt 12 ]; do
      next=""
      for p in $frontier; do
        kids="$(printf '%s\n' "$snapshot" | awk -v pp="$p" '$2 == pp { print $1 }' || true)"
        if [ -n "$kids" ]; then next="$next $kids"; fi
      done
      frontier="$next"
      if [ -n "$frontier" ]; then targets="$targets $frontier"; fi
      depth=$((depth + 1))
    done
  fi

  # TERM dalle foglie verso la radice, così i padri non rilanciano i figli.
  local ordered="" t
  for t in $targets; do ordered="$t $ordered"; done
  for t in $ordered; do kill "$t" 2>/dev/null || true; done

  local i=0
  while [ "$i" -lt 30 ] && pid_alive "$pid"; do
    i=$((i + 1))
    # `sleep 0.1` non è POSIX: se non supportato si ripiega su un secondo.
    sleep 0.1 2>/dev/null || sleep 1
  done

  # Chi resiste viene abbattuto.
  for t in $ordered; do
    if kill -0 "$t" 2>/dev/null; then kill -9 "$t" 2>/dev/null || true; fi
  done
}

cleanup() {
  [ "$CLEANED" -eq 0 ] || return 0
  CLEANED=1
  trap - INT TERM EXIT
  local had=0
  # Si toccano SOLO i processi e i file PID creati da *questa* esecuzione:
  # un avvio abortito (es. porta occupata) non deve disarmare l'istanza già viva.
  if [ -n "$FRONTEND_PID" ]; then
    if pid_alive "$FRONTEND_PID"; then had=1; kill_pid_tree "$FRONTEND_PID"; fi
    rm -f "$FRONTEND_PID_FILE" 2>/dev/null || true
  fi
  if [ -n "$BACKEND_PID" ]; then
    if pid_alive "$BACKEND_PID"; then had=1; kill_pid_tree "$BACKEND_PID"; fi
    rm -f "$BACKEND_PID_FILE" 2>/dev/null || true
  fi
  [ "$had" -eq 0 ] || printf '\n%s\n' "${C_GREEN}✓${C_RESET} Server fermati. Alla prossima!"
}

cmd_stop() {
  local stopped=0 pid
  pid="$(read_pid_file "$FRONTEND_PID_FILE")"
  if pid_alive "$pid"; then kill_pid_tree "$pid"; ok "frontend (pid $pid) fermato"; stopped=1; fi
  pid="$(read_pid_file "$BACKEND_PID_FILE")"
  if pid_alive "$pid"; then kill_pid_tree "$pid"; ok "backend (pid $pid) fermato"; stopped=1; fi
  rm -f "$BACKEND_PID_FILE" "$FRONTEND_PID_FILE" 2>/dev/null || true
  [ "$stopped" -eq 1 ] || info "Nessun processo attivo registrato in .run/."
}

# ------------------------------------------------------------------ servers --
start_backend() {
  require_free_port "$BACKEND_PORT" "backend" "--backend-port"
  mkdir -p "$RUN_DIR" "$LOG_DIR"
  : > "$BACKEND_LOG"
  info "avvio backend su http://127.0.0.1:$BACKEND_PORT (LDAP_MODE=$LDAP_MODE_ARG)…"
  (
    cd "$BACKEND_DIR"
    LDAP_MODE="$LDAP_MODE_ARG" php -S "127.0.0.1:$BACKEND_PORT" -t public public/index.php 2>&1 \
      | while IFS= read -r line; do
          printf '%s\n' "$line" >> "$BACKEND_LOG"
          printf '%s %s\n' "${C_DIM}[backend]${C_RESET}" "$line"
        done
  ) &
  BACKEND_PID=$!
  echo "$BACKEND_PID" > "$BACKEND_PID_FILE"
}

wait_for_health() {
  local url="http://127.0.0.1:$BACKEND_PORT/api/v1/health"
  local i=0
  info "attendo che il backend risponda su $url…"
  while [ "$i" -lt 60 ]; do
    if command -v curl >/dev/null 2>&1; then
      if curl -fsS --max-time 2 "$url" >/dev/null 2>&1; then ok "backend pronto"; return 0; fi
    else
      if port_in_use "$BACKEND_PORT"; then ok "backend in ascolto (curl assente: health non verificata)"; return 0; fi
    fi
    if ! pid_alive "$BACKEND_PID"; then break; fi
    i=$((i + 1))
    sleep 0.5 2>/dev/null || sleep 1
  done
  err "Il backend non è diventato healthy entro 30 secondi."
  err "Ultime righe di $BACKEND_LOG:"
  tail -n 25 "$BACKEND_LOG" 2>/dev/null >&2 || true
  exit 4
}

start_frontend() {
  require_free_port "$FRONTEND_PORT" "frontend" "--frontend-port"
  mkdir -p "$RUN_DIR" "$LOG_DIR"
  : > "$FRONTEND_LOG"
  info "avvio frontend su http://localhost:$FRONTEND_PORT…"
  (
    cd "$FRONTEND_DIR"
    VITE_API_TARGET="http://127.0.0.1:$BACKEND_PORT" \
      "$NPM_BIN" run dev -- --port "$FRONTEND_PORT" --strictPort 2>&1 \
      | while IFS= read -r line; do
          printf '%s\n' "$line" >> "$FRONTEND_LOG"
          printf '%s %s\n' "${C_DIM}[frontend]${C_RESET}" "$line"
        done
  ) &
  FRONTEND_PID=$!
  echo "$FRONTEND_PID" > "$FRONTEND_PID_FILE"
}

banner() {
  local driver db
  driver="$(db_driver)"
  db="$(sqlite_path)"
  [ -n "$db" ] || db="(host: $( [ -f "$BACKEND_DIR/.env" ] && grep -m1 '^DB_HOST=' "$BACKEND_DIR/.env" | cut -d= -f2- || echo '?' ))"
  cat <<EOF

${C_BOLD}${C_BLUE}╔══════════════════════════════════════════════════════════════════╗
║  Visionary Lab — piattaforma prestito attrezzature               ║
╚══════════════════════════════════════════════════════════════════╝${C_RESET}

  ${C_BOLD}Applicazione${C_RESET}   ${C_GREEN}http://localhost:$FRONTEND_PORT${C_RESET}
  ${C_BOLD}API${C_RESET}            http://127.0.0.1:$BACKEND_PORT/api/v1
  ${C_BOLD}Health check${C_RESET}   http://127.0.0.1:$BACKEND_PORT/api/v1/health
  ${C_BOLD}LDAP${C_RESET}           $LDAP_MODE_ARG
  ${C_BOLD}Database${C_RESET}       $driver — $db
  ${C_BOLD}Log${C_RESET}            backend/storage/logs/

  ${C_BOLD}Utenti di prova${C_RESET} (password identica per tutti: ${C_BOLD}password${C_RESET})
  ┌───────────────┬──────────────┬──────────────────────────────────┐
  │ username      │ password     │ ruolo                            │
  ├───────────────┼──────────────┼──────────────────────────────────┤
  │ student1      │ password     │ studente                         │
  │ student2      │ password     │ studente                         │
  │ tecnico1      │ password     │ tecnico                          │
  │ borsista1     │ password     │ borsista                         │
  │ admin1        │ password     │ amministratore                   │
  └───────────────┴──────────────┴──────────────────────────────────┘

  ${C_DIM}Premi Ctrl-C per fermare entrambi i server.${C_RESET}

EOF
}

# -------------------------------------------------------------------- tests --
cmd_test() {
  local failed=0
  info "PHPUnit…"
  if [ -x "$BACKEND_DIR/vendor/bin/phpunit" ]; then
    ( cd "$BACKEND_DIR" && vendor/bin/phpunit ) || failed=1
  else
    err "vendor/bin/phpunit assente: esegui prima ./run.sh install"
    failed=1
  fi
  info "Vitest…"
  ( cd "$FRONTEND_DIR" && "$NPM_BIN" run test ) || failed=1
  if [ "$failed" -eq 0 ]; then
    ok "tutte le suite sono verdi"
  else
    err "almeno una suite è fallita"
  fi
  return "$failed"
}

# --------------------------------------------------------------------- main --
main() {
  parse_args "$@"

  case "$COMMAND" in
    help) usage; exit 0 ;;
    stop) cmd_stop; exit 0 ;;
  esac

  printf '%s\n\n' "${C_BOLD}Visionary Lab${C_RESET} ${C_DIM}(os: $OS)${C_RESET}"

  if [ "$DO_INSTALL_RUNTIME" -eq 1 ]; then
    install_runtime || true
  fi

  require_prereqs

  case "$COMMAND" in
    install-runtime)
      exit 0
      ;;
    install)
      DO_INSTALL=1
      do_install
      exit 0
      ;;
    test)
      if cmd_test; then exit 0; else exit 1; fi
      ;;
    migrate)
      bootstrap_env; do_install; prepare_db; run_migrations
      exit 0
      ;;
    seed)
      bootstrap_env; do_install; prepare_db; run_seed
      exit 0
      ;;
  esac

  bootstrap_env
  do_install

  # Ctrl-C / TERM = arresto pulito richiesto dall'utente → exit 0.
  # Il trap su EXIT copre invece le uscite per errore, senza alterarne il codice.
  trap 'cleanup; exit 0' INT TERM
  trap cleanup EXIT

  case "$COMMAND" in
    frontend)
      start_frontend
      banner
      wait "$FRONTEND_PID" || true
      exit 0
      ;;
    backend)
      prepare_db; run_migrations; run_seed
      start_backend
      wait_for_health
      banner
      wait "$BACKEND_PID" || true
      exit 0
      ;;
  esac

  # start / fresh
  prepare_db
  run_migrations
  run_seed
  start_backend
  wait_for_health
  start_frontend
  banner

  # Attesa in foreground: se uno dei due esce, si termina anche l'altro.
  local status=0
  while :; do
    if ! pid_alive "$BACKEND_PID"; then
      wait "$BACKEND_PID" 2>/dev/null || status=$?
      [ "$status" -eq 0 ] || err "Il backend è terminato con stato $status."
      break
    fi
    if ! pid_alive "$FRONTEND_PID"; then
      wait "$FRONTEND_PID" 2>/dev/null || status=$?
      [ "$status" -eq 0 ] || err "Il frontend è terminato con stato $status."
      break
    fi
    sleep 1
  done

  cleanup
  exit "$status"
}

main "$@"
