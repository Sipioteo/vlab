# Fonts shipped with Visionary Lab

Both families are self-hosted (latin subset, `.woff2`) so the application makes
**no runtime request to fonts.googleapis.com / fonts.gstatic.com**.

## Poppins — `poppins-{300,400,500,600,700}.woff2`

Copyright (c) 2020 The Poppins Project Authors
(https://github.com/itfoundry/Poppins)

Licensed under the **SIL Open Font License, Version 1.1**.
Full text: https://openfontlicense.org/open-font-license-official-text/

## Roboto — `roboto-variable.woff2`

Copyright (c) 2011 The Roboto Project Authors
(https://github.com/googlefonts/roboto-3-classic)

Licensed under the **SIL Open Font License, Version 1.1**.
Full text: https://openfontlicense.org/open-font-license-official-text/

Google Fonts serves the latin subset of Roboto as a single variable file that
covers the whole 100–900 weight axis, so one file backs every weight the UI
uses (300/400/500/700) via `font-weight: 100 900` in the `@font-face` rule.

## Refreshing

```
curl "https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Roboto:wght@300;400;500;700&display=swap"
```
then download the `latin` subset `.woff2` URLs from the response.
`@font-face` declarations live in `frontend/src/styles/fonts.css`.
