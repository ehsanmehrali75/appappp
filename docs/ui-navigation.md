# Main panel navigation

The enhanced shell keeps the PHP permission-filtered links as its source of truth. JavaScript moves existing nodes; it never builds a separate route or permission catalog. The legacy design and no-JavaScript navigation remain available.

- Project context and search come first. Overview, products and publication calendar are permanent daily shortcuts when authorized.
- Specialist tools are grouped by task. Groups start closed except the current section; opening or closing groups lasts for the current page. System administration follows project work.
- Search normalizes Persian/Arabic yeh and kaf and removes diacritics and zero-width joiners. Matching group names reveal their tools. Clearing search restores the previous disclosure state. No-result feedback is announced through a status region.
- All original destinations, visibility badges and real counters remain intact. Active links have `aria-current`; disclosure buttons control hidden panels.
- Header shows internal chat and tasks; other existing actions remain in a native “Other access” disclosure, including logout and owner setup. Escape closes it and restores focus.
- Mobile retains a modal drawer, keyboard containment, background inertness and explicit close button. Tables and module data are unchanged.

Validated on a synthetic local installation: all 54 authorized sidebar destinations preserved; search/no results/restoration; active settings group; header Escape behavior; drawer close; 1440, 1024, 768, 375 and 320 px with no horizontal page overflow. Existing settings, users, extraction, calendar and dashboard checks also passed at 1440/375/320 with no JavaScript errors. The authenticated production URL was inaccessible from this environment (proxy 403); this is not a production deployment check.
