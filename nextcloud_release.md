# Nextcloud Budget — Release Process

## Prerequisites

- Docker container `nc` running (`ghcr.io/juliusknorr/nextcloud-dev-php83`)
- App source at `d:\Nextcloud\Projects\Nextcloud Budget\budget`
- App mounted in container at `/var/www/html/apps-extra/budget`
- Signing certificate and key at `~/.nextcloud/certificates/budget.crt` and `budget.key`
- `gh` CLI authenticated with GitHub
- npm installed locally for frontend builds

## Pre-Release Checks

### 1. Run full test suite

```bash
docker exec nc bash -c "cd /var/www/html/apps-extra/budget && vendor/bin/phpunit -c tests/phpunit.xml 2>&1 | tail -5"
```

Expected: `OK` with 0 failures, 0 errors.

### 2. Validate migrations

Check for common cross-database issues:

```bash
# Boolean columns must use 'notnull' => false for cross-DB compatibility
docker exec nc bash -c "grep -rn 'BOOLEAN' /var/www/html/apps-extra/budget/lib/Migration/ | grep 'notnull.*true'"

# Table names must be under 24 chars (oc_ prefix + name ≤ 27)
docker exec nc bash -c "grep -oP \"createTable\('\K[^']+\" /var/www/html/apps-extra/budget/lib/Migration/*.php | while read -r line; do f=\${line%%:*}; n=\${line#*:}; len=\${#n}; if [ \$len -gt 23 ]; then echo \"FAIL: \$f — table '\$n' is \$len chars (max 23, becomes oc_\$n = \$((len+3)))\"; fi; done"

# Index names must be under 24 chars (oc_ prefix + name ≤ 27)
docker exec nc bash -c "grep -oP \"(?:addIndex|addUniqueIndex|setPrimaryKey).*?'\\K[^']+\" /var/www/html/apps-extra/budget/lib/Migration/*.php 2>/dev/null | while read -r line; do f=\${line%%:*}; n=\${line#*:}; len=\${#n}; if [ \$len -gt 23 ]; then echo \"FAIL: \$f — index '\$n' is \$len chars (max 23)\"; fi; done"
```

Should produce no FAIL output. If it does, shorten the table or index name.

### 3. Compile translations

```bash
docker exec nc bash -c 'cd /var/www/html/apps-extra/budget && php translationtool.phar convert-po-files'
```

This regenerates `l10n/*.js` and `l10n/*.json` from all `.po` files. Always run this before a release to pick up any Weblate contributions.

### 4. Build frontend

```bash
cd budget && npm run build
```

Must compile with no errors (warnings about bundle size are OK).

### 5. PHP lint

```bash
docker exec nc bash -c "cd /var/www/html/apps-extra/budget && find lib/ appinfo/ -name '*.php' -exec php -l {} \; 2>&1" | grep -v "No syntax errors"
```

Should produce no output (no syntax errors).

## Release Steps

### 1. Update version and changelog

**`budget/appinfo/info.xml`** — bump `<version>`:
```xml
<version>X.Y.Z</version>
```

**`budget/CHANGELOG.md`** — add entry at the top:
```markdown
## [X.Y.Z] - YYYY-MM-DD

### Added
- ...

### Fixed
- ...
```

**`README.md`** — update "What's New" section if a minor/major release.

### 2. Commit, tag, push

```bash
git add budget/appinfo/info.xml budget/CHANGELOG.md README.md
git commit -m "chore: Bump version to X.Y.Z"
git tag vX.Y.Z
git push origin master
git push origin vX.Y.Z
```

### 3. Clone fresh from tag in Docker

```bash
docker exec nc bash -c 'cd /tmp && rm -rf budget-vX.Y.Z && git clone --depth 1 --branch vX.Y.Z https://github.com/otherworld-dev/budget.git budget-vX.Y.Z'
```

### 4. Install PHP dependencies (production)

```bash
docker exec nc bash -c 'cd /tmp/budget-vX.Y.Z/budget && composer install --no-dev --optimize-autoloader --no-interaction'
```

### 5. Build frontend from tag on host

```bash
cd "d:/Nextcloud/Projects/Nextcloud Budget/budget"
git checkout vX.Y.Z
npm run build
```

### 6. Create clean build directory

Strip dev files (tests, source, node_modules, build configs):

```bash
docker exec nc bash -c 'cd /tmp/budget-vX.Y.Z/budget && rm -rf /tmp/budget-build && rsync -a \
  --exclude=".git" --exclude="build" --exclude="tests" --exclude="node_modules" \
  --exclude="src" --exclude="webpack.config.js" --exclude="package.json" \
  --exclude="package-lock.json" --exclude="composer.json" --exclude="composer.lock" \
  --exclude="Makefile" --exclude=".gitignore" --exclude=".eslintrc.js" \
  --exclude="psalm.xml" --exclude="*.log" --exclude="docs/superpowers" \
  ./ /tmp/budget-build/'
```

### 7. Copy built JS/CSS into build directory

The host-built frontend assets need to be copied into the container's build directory:

```bash
docker cp "d:/Nextcloud/Projects/Nextcloud Budget/budget/js" nc:/tmp/budget-build/
docker cp "d:/Nextcloud/Projects/Nextcloud Budget/budget/css" nc:/tmp/budget-build/
```

### 8. Clean problematic files

```bash
docker exec nc bash -c 'rm -f /tmp/budget-build/vendor/tecnickcom/tcpdf/tools/.htaccess'
```

### 9. Verify build directory

```bash
# No dev files
docker exec nc bash -c 'find /tmp/budget-build -name "package.json" -o -name "composer.json" -o -name "Makefile" -o -name ".htaccess" -o -name "webpack.config.js" | wc -l'
# Expected: 0

# No dev dependencies
docker exec nc bash -c 'ls /tmp/budget-build/vendor/ | grep -E "phpunit|psalm|myclabs|nextcloud/ocp" | wc -l'
# Expected: 0
```

### 10. Copy signing certificates to container

```bash
docker cp ~/.nextcloud/certificates/budget.key nc:/tmp/budget.key
docker cp ~/.nextcloud/certificates/budget.crt nc:/tmp/budget.crt
```

### 11. Sign the app

```bash
docker exec nc bash -c 'chmod -R 777 /tmp/budget-build && php occ integrity:sign-app --privateKey=/tmp/budget.key --certificate=/tmp/budget.crt --path=/tmp/budget-build'
```

Expected: `Successfully signed "/tmp/budget-build"`

### 12. Create tarball

The tarball must contain a top-level `budget/` directory:

```bash
docker exec nc bash -c 'cd /tmp && rm -rf budget && mv budget-build budget && tar -czf budget-vX.Y.Z.tar.gz budget'
```

### 13. Generate app store signature

```bash
docker exec nc bash -c 'openssl dgst -sha512 -sign /tmp/budget.key /tmp/budget-vX.Y.Z.tar.gz | openssl base64 -A'
```

**Save this output** — you need it for the app store submission.

### 14. Copy tarball to host

```bash
docker cp nc:/tmp/budget-vX.Y.Z.tar.gz "d:/Nextcloud/Projects/Nextcloud Budget/budget.tar.gz"
```

### 15. Verify tarball

```bash
cd "d:/Nextcloud/Projects/Nextcloud Budget"

# No dev files
tar -tzf budget.tar.gz | grep -cE "(package\.json|composer\.json|Makefile|webpack\.config|tests/)"
# Expected: 0

# No .htaccess
tar -tzf budget.tar.gz | grep -c '\.htaccess'
# Expected: 0

# Signature present
tar -tzf budget.tar.gz budget/appinfo/signature.json
# Expected: lists the file

# Reasonable size (~17MB)
ls -lh budget.tar.gz
```

### 16. Create GitHub release

```bash
gh release create vX.Y.Z --repo otherworld-dev/budget --title "vX.Y.Z" --notes "$(cat <<'EOF'
## Summary

- bullet points here

See the [full changelog](https://github.com/otherworld-dev/budget/blob/master/budget/CHANGELOG.md) for details.
EOF
)"

gh release upload vX.Y.Z budget.tar.gz --repo otherworld-dev/budget
```

### 17. Return to master

```bash
git checkout master
```

### 18. Submit to Nextcloud App Store

Go to: **https://apps.nextcloud.com/developer/apps/releases/new**

- **Download URL:**
  ```
  https://github.com/otherworld-dev/budget/releases/download/vX.Y.Z/budget.tar.gz
  ```

- **Signature:** paste the base64 string from step 13

Click Submit.

## Quick Reference — Copy/Paste Template

Replace `X.Y.Z` with the version number throughout:

```bash
# === PRE-RELEASE ===
docker exec nc bash -c "cd /var/www/html/apps-extra/budget && vendor/bin/phpunit -c tests/phpunit.xml 2>&1 | tail -5"
docker exec nc bash -c 'cd /var/www/html/apps-extra/budget && php translationtool.phar convert-po-files'
cd budget && npm run build

# === VERSION BUMP (edit files first) ===
git add budget/appinfo/info.xml budget/CHANGELOG.md README.md
git commit -m "chore: Bump version to X.Y.Z"
git tag vX.Y.Z
git push origin master && git push origin vX.Y.Z

# === BUILD TARBALL ===
docker exec nc bash -c 'cd /tmp && rm -rf budget-vX.Y.Z && git clone --depth 1 --branch vX.Y.Z https://github.com/otherworld-dev/budget.git budget-vX.Y.Z'
docker exec nc bash -c 'cd /tmp/budget-vX.Y.Z/budget && composer install --no-dev --optimize-autoloader --no-interaction'
git checkout vX.Y.Z && npm run build
docker exec nc bash -c 'cd /tmp/budget-vX.Y.Z/budget && rm -rf /tmp/budget-build && rsync -a --exclude=".git" --exclude="build" --exclude="tests" --exclude="node_modules" --exclude="src" --exclude="webpack.config.js" --exclude="package.json" --exclude="package-lock.json" --exclude="composer.json" --exclude="composer.lock" --exclude="Makefile" --exclude=".gitignore" --exclude=".eslintrc.js" --exclude="psalm.xml" --exclude="*.log" --exclude="docs/superpowers" ./ /tmp/budget-build/'
docker cp budget/js nc:/tmp/budget-build/ && docker cp budget/css nc:/tmp/budget-build/
docker exec nc bash -c 'rm -f /tmp/budget-build/vendor/tecnickcom/tcpdf/tools/.htaccess'

# === SIGN & PACKAGE ===
docker cp ~/.nextcloud/certificates/budget.key nc:/tmp/budget.key
docker cp ~/.nextcloud/certificates/budget.crt nc:/tmp/budget.crt
docker exec nc bash -c 'chmod -R 777 /tmp/budget-build && php occ integrity:sign-app --privateKey=/tmp/budget.key --certificate=/tmp/budget.crt --path=/tmp/budget-build'
docker exec nc bash -c 'cd /tmp && rm -rf budget && mv budget-build budget && tar -czf budget-vX.Y.Z.tar.gz budget'

# === SIGNATURE (save this!) ===
docker exec nc bash -c 'openssl dgst -sha512 -sign /tmp/budget.key /tmp/budget-vX.Y.Z.tar.gz | openssl base64 -A'

# === COPY & VERIFY ===
docker cp nc:/tmp/budget-vX.Y.Z.tar.gz budget.tar.gz
tar -tzf budget.tar.gz budget/appinfo/signature.json

# === GITHUB RELEASE ===
gh release create vX.Y.Z --repo otherworld-dev/budget --title "vX.Y.Z" --notes "Release notes here"
gh release upload vX.Y.Z budget.tar.gz --repo otherworld-dev/budget
git checkout master

# === APP STORE ===
# URL: https://github.com/otherworld-dev/budget/releases/download/vX.Y.Z/budget.tar.gz
# Signature: (from step above)
# Submit at: https://apps.nextcloud.com/developer/apps/releases/new
```
