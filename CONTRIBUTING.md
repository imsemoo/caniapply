# Contributing

The most useful help is a job post that gets the wrong verdict for your country. Open an issue with the location text, the sentence that matters, your country, and the verdict you expected.

**Adding a country:** add one row to `app/Support/HomeCountry.php`, with its name, ISO code, usual UTC offset, regions, and a pattern with its main cities.

**Adding a source:** add a class in `app/Sources/Connectors` and a line in `config/hunter.php`. Link to the source's terms in your pull request. Sources that forbid automated reading will not be merged.

Run the tests before you open a pull request:

```bash
php artisan test
```
