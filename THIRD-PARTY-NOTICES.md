# Third-party notices

This file describes third-party material redistributed in the production plugin assets. Development-only packages recorded in `package-lock.json` are not included merely because they are used to build or validate the project.

## WordPress element runtime

React, React DOM, and Scheduler are development-only packages used for TypeScript declarations and build tooling. Their implementations are not embedded in the generated plugin bundles. The administration application uses the `wp-element` runtime supplied and registered by WordPress.

## Geist font

The plugin redistributes regular, medium, semibold, and bold Geist webfont files in both the source assets and generated production assets.

Copyright 2024 The Geist Project Authors (https://github.com/vercel/geist-font.git)

Geist is licensed under the SIL Open Font License, Version 1.1. The required copyright notice and complete license are distributed in `assets/fonts/geist/OFL.txt`.

## WordPress internationalization runtime

`@wordpress/i18n` is declared for TypeScript and package resolution, but the production build aliases it to the `window.wp.i18n` runtime supplied by WordPress. Its npm implementation and transitive packages are not embedded in the generated plugin bundles.

The WordPress package is GPL-2.0-or-later. Its dependency and license metadata remain recorded in `package-lock.json` for reproducible development installs.
