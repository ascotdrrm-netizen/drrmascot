# Presentation incident

`flood-gym-synthetic.png` is an AI-generated demo illustration, visibly marked **DEMO • SYNTHETIC IMAGE**. It depicts no identified school or actual incident.

After the pending migrations are applied to a local presentation database with campuses and accounts, run:

```powershell
php artisan db:seed --class=PresentationDemoSeeder
```

The seeder is repeatable and creates a described building/area, a flood incident with photo and recorded people counts, and a damage report. It refuses production environments. It has not been run against the live database.

Generated using the built-in image generation tool with this prompt:

> Use case: photorealistic-natural. Asset type: synthetic incident photograph for a Philippine university disaster management software DEMO. Primary request: a realistic phone snapshot showing shallow muddy floodwater at the entrance of a generic low-rise campus gymnasium after heavy rain. Open concrete walkway with drainage blocked by fallen leaves, a plain roofed building and tropical trees in the background, overcast daylight, ordinary slightly imperfect handheld framing, believable restrained damage. No people, no injuries, no identifiable real school, no logos. Include a small clear unobtrusive caption in the lower right reading exactly "DEMO • SYNTHETIC IMAGE" so it cannot be mistaken for documentation of a real event. Landscape 4:3 composition, natural muted colors, factual documentation style.
