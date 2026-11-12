# Демо на GitHub Pages

Статическая сборка для просмотра «Требуют внимания», проектов, задач и их
обсуждений. Переключатель демо-пользователей показывает данные, которые
соответствующий пользователь получил бы от серверного API. Страница и её
данные публичны: используются только вымышленные записи из демо-набора.

GitHub Pages не запускает PHP. Поэтому демо читает снимок, созданный
из временной SQLite в памяти, и работает в режиме просмотра. Изменения задач
и отправка комментариев доступны в локальной версии через Docker Compose.

Для сборки из ветки `pages-demo` нужны PHP с PDO SQLite, Node 22.23.3
и npm. Из корня репозитория:

```bash
(cd frontend && npm ci && npm run build -- --configuration production,pages)
php scripts/export-pages.php frontend/dist/project-desk/browser/demo-data.json
touch frontend/dist/project-desk/browser/.nojekyll
```

GitHub Pages публикует содержимое `frontend/dist/project-desk/browser/`
из корня ветки `gh-pages`. Базовый адрес сборки — `/SherpaHR/`.
Чтобы обновить демо, пересоберите статические файлы и снимок вместе.
