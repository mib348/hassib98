# MUST FOLLOW RULES

## CURRENT PROJECT RULES

-   Whenever you are stuck in an error fixing loop, utilize the help of grep mcp tool proactively or searching @web for `http://grep.app` for example `https://grep.app/search?q=DOMPX_TASKGRAPH` or searching @web for stackoverflow for related solutions.
-   Use grep mcp tool to fix issues/bugs/errors, using grep search pattern.
-   Any new or changed embedded admin page, button cluster, or major table must include an inline `partials.admin_help_tooltip` explanation wherever it helps the admin understand the feature or action fastest.

## SYSTEM HANDLING RULES

-   Run this command powershell.exe -WindowStyle Hidden -Command "(New-Object Media.SoundPlayer 'C:\Windows\Media\notify.wav').PlaySync()" whenever you are done with a task.
-   Do not install anything on the host.
-   Use grep mcp tool to fix issues/bugs/errors, using grep search pattern.

## TASK HANDLING RULES

-   Address each issue one by one, fixing and verifying before checking it off and moving to the next.
-   You are an agent - please keep going until the user's query is completely resolved, before ending your turn and yielding back to the user.
-   Only terminate your turn when you are sure that the problem is solved.
-   Never stop or hand back to the user when you encounter uncertainty — research or deduce the most reasonable approach and continue.
-   Do not ask the human to confirm or clarify assumptions, as you can always adjust later — decide what the most reasonable assumption is, proceed with it, and document it for the user's reference after you finish acting.
-   Only modify code directly relevant to the specific request. Avoid changing unrelated functionality.
-   Explain your OBSERVATIONS clearly, then provide REASONING to identify the exact issue. Add console logs when needed to gather more information.
-   Never replace code with placeholders like `// ... rest of the processing ...`. Always include complete code.
-   Never leave incomplete stubs.
-   Whenever you are stuck in an error fixing loop, utilize the help of grep mcp tool proactively or searching @web for `http://grep.app` for example `https://grep.app/search?q=DOMPX_TASKGRAPH` or searching @web for stackoverflow for related solutions.
-   Use grep mcp tool to fix issues/bugs/errors, using grep search pattern.

## CODING RULES

-   I prefer utilizing the existing code to the maximum.
-   Do not add unnecessary complexity to the code.
-   Do the task with minimal code.
-   Follow the existing coding structure strictly.
-   Do not change anything else.
-   Don't add redundancy to the code.
-   Do not hardcode anything or use placeholders.
-   Do not break anything else.
-   Follow the rules strictly.
-   Do to create unnecessary files.
-   Add deeply understandle with easy explanation extensive comments to the code you write. Comments should be simple and so much deep explanatory that i can instantly understand and recall what the code block is about as a beginner or comebacker.
-   Focus on the areas of code relevant to the task
-   Do not touch code that is unrelated to the task
-   Write thorough tests for all major functionality
-   Avoid making major changes to the patterns and architecture of how a feature works, after it has shown to work well, unless explicitly instructed
-   Always think about what other methods and areas of code might be affected by code changes
-   Always prefer simple solutions whenever possible
-   Avoid duplication of code whenever possible, which means checking for other areas of the codebase that might already have similar code and functionality
-   Write code that takes into account the different environments: dev, test, and prod
-   You are careful to only make changes that are requested or you are confident are well understood and related to the change being requested
-   When fixing an issue or bug, do not introduce a new pattern or technology without first exhausting all options for the existing implementation. And if you finally do this, make sure to remove the old implementation afterwards so we don’t have duplicate logic.
-   Keep the codebase very clean and organized
-   Avoid writing scripts in files if possible, especially if the script is likely only to be run once.
-   Mocking data is only needed for tests, never mock data for dev or prod
-   Never add stubbing or fake data patterns to code that affects the dev or prod environments
-   Never overwrite my .env file without first asking and confirming.
-   Never use placeholder values.
-   Never use hardcoded values.
-   Ensure no code is missing for required meticulous conversion.
-   Double check for syntax errors.
-   Double check for lint errors.
-   Double check for missing or incomplete code and complete it.
-   avoid changing variable names or file names.
-   Do not modify logic other than optimization purpose i.e only if 100% score sure.
-   ask if confusions.
-   always give full code i.e is production ready.
-   try to avoid changing directory structure too much.
-   try to avoid splitting files unnecessarily.
-   use minimal number of files if possible or advised.
-   if asked for replication/mimic then always work it out meticulously, ask questions if ambiguity arises.
-   do not add new files of any type unless required.
-   Make sure to keep the project root folder clean & organized.
-   Keep the test files in the tests folder.
-   do not assume the api payload from yourself unless told to do so.
-   Never replace code with placeholders like `// ... rest of the processing ...`. Always include complete code.
-   Never leave incomplete stubs.

<persistence>
- You are an agent - please keep going until the user's query is completely resolved, before ending your turn and yielding back to the user.
- Only terminate your turn when you are sure that the problem is solved.
- Never stop or hand back to the user when you encounter uncertainty — research or deduce the most reasonable approach and continue.
- Do not ask the human to confirm or clarify assumptions, as you can always adjust later — decide what the most reasonable assumption is, proceed with it, and document it for the user's reference after you finish acting
</persistence>

# Repository Guidelines

## Project Structure & Module Organization

-   `app/Http/Controllers` handles Shopify-linked flows (orders, drivers, kitchens, locations); `app/Livewire` houses reactive dashboards like `Stores/StoresList`.
-   Models live in `app/Models`; migrations/seeders/factories under `database/` provide sample data pipelines.
-   UI sits in `resources/views` with companion assets in `resources/js` and `resources/css`; map routes in `routes/web.php` and `routes/api.php`.
-   Feature tests in `tests/Feature` should shadow controller groups and route names.

## Build, Test, and Development Commands

-   `composer install` and `npm install` prepare PHP and Vite dependencies.
-   `php artisan serve --port=8000` launches the app; run `npm run dev` for hot module reloads.
-   `php artisan migrate --seed` loads baseline locations/products; keep `php artisan queue:work` running so Shopify jobs process locally.
-   Data sync jobs: `php artisan shopify:import-products`, `shopify:import-orders`, `shopify:import-locations`, `shopify:import-revenue`, `shopify:import-drivers-orders`; refresh metafields with `php artisan shopify:update-product-metafields --current`.

## Coding Style & Naming Conventions

-   Code targets PHP 8.1, PSR-12, 4-space indentation; format with `./vendor/bin/pint` before committing.
-   Controllers must end in `Controller`, Livewire classes stay PascalCase (`App\Livewire\Stores\StoresList`), and Blade files use snake_case like `resources/views/drivers/cleaning_status.blade.php`.
-   JavaScript modules export camelCase symbols, and env keys remain uppercase (`SHOPIFY_APP_API_KEY`) sourced from `.env`.

## Testing Guidelines

-   Run the suite with `php artisan test`; focus runs via `php artisan test tests/Feature/ShopifyBladeTemplateTest.php`.
-   Name classes `SomethingFeatureTest` and mirror user workflows in method names (e.g., `test_driver_can_upload_cleaning_photo`).
-   Stub Shopify traffic with fakes and assert queue dispatches so tests stay deterministic.

## Commit & Pull Request Guidelines

-   Mimic history: short present-tense subjects referencing the surface (“drivers app”, “locations revenue”), plus body notes on scope and risks.
-   Link tickets, list database or queue effects, and include UI screenshots when tweaking Blade or Livewire.
-   PR descriptions should log verification steps (artisan commands, test runs) and highlight new env keys or scheduled tasks.

## Security & Configuration Notes

-   Keep Shopify credentials confined to `.env` and `config/shopify.php`; rotate tokens before running import commands against production stores.
-   After config tweaks run `php artisan optimize:clear`, and purge sensitive logs via Log Viewer instead of committing `storage/logs`.
