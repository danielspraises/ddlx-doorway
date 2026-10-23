<!-- LOVABLE:BEGIN -->
> [!IMPORTANT]
> This project is connected to [Lovable](https://lovable.dev). Avoid rewriting
> published git history — force pushing, or rebasing/amending/squashing commits
> that are already pushed — as it rewrites history on Lovable's side and the
> user will likely lose their project history.
>
> Commits you push to the connected branch sync back to Lovable and show up in
> the editor, so keep the branch in a working state.
<!-- LOVABLE:END -->

- Render the decorative vendor scene with dynamically imported Three.js inside a client effect; keep rendering and cleanup browser-only to preserve SSR safety.
- Keep vendor visuals in semantic global CSS tokens and shared scene/header components so public and account pages stay consistent.
- Build approved-seller tools as focused pages in one seller workspace; avoid a separate generic dashboard because the platform itself is the management home.
- The app owns its PostgreSQL database (db/migrations, applied with `npm run db:migrate`). Load application and review data through authenticated route loaders and shared query options that call server functions (`src/lib/*.functions.ts`); those functions check the session, ownership and admin role on the server. Server-only code lives in `src/server/*.server.ts` and is imported dynamically inside handlers.
- Share section forms and review summaries between seller and staff pages so conditional fields and submitted details stay consistent.
