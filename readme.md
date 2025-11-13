# =========================================================
# Root: ignore everything by default
# =========================================================
*

# =========================================================
# Allow only these:
# - README.md (or readme.md)
# - .env.example
# - sql/** (entire folder)
# - public/** (entire folder)
# =========================================================
!.gitignore
!README.md
!readme.md
!.env.example
!/sql/
!/sql/**
!/public/
!/public/**

# =========================================================
# Sensitive / build / local files to keep OUT of git
# (Most of these are redundant due to the whitelist above,
# but they’re here for safety and clarity.)
# =========================================================
# Secrets
.env
.env.local
.env.*.local

# Composer / PHP deps
/vendor/
/composer.lock
/composer.json

# OS junk
.DS_Store
Thumbs.db

# IDE / editor
.vscode/
.idea/
*.iml

# Logs & caches
*.log
storage/
cache/
tmp/
.phpunit.result.cache
coverage/

# Node (just in case)
/node_modules/
