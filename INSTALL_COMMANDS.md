# Install This Pack Into Your Project

From outside the project folder, copy the files manually or unzip this pack.

After copy, run from project root:

```bash
git status
git add CLAUDE.md .claudeignore docs prompts
git commit -m "Add Claude project guide and instructions"
git push origin master
```

On another PC:

```bash
cd D:\Projects\Garments-BOM-OCR-Management-System
git checkout master
git pull origin master
composer install
npm install
npm run build
php artisan optimize:clear
php artisan migrate
php artisan serve
```

If database is already updated and no migration changed, `php artisan migrate` may show nothing to migrate.
