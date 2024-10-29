require("dotenv").config();

var Encore = require("@symfony/webpack-encore");

// Manually configure the runtime environment if not already configured yet by the "encore" command.
// It's useful when you use tools that rely on webpack.config.js file.
if (!Encore.isRuntimeEnvironmentConfigured()) {
  Encore.configureRuntimeEnvironment(process.env.NODE_ENV || "dev");
}

Encore
  // Directory where compiled assets will be stored
  .setOutputPath("public/build/")
  // Public path used by the web server to access the output path
  .setPublicPath("/build")
  // Only needed for CDN's or sub-directory deploy
  //.setManifestKeyPrefix('build/')

  // ENTRY CONFIG
  .addEntry("app", "./assets/js/app.js")
  //.addEntry('page1', './assets/js/page1.js')
  //.addEntry('page2', './assets/js/page2.js')

  // When enabled, Webpack "splits" your files into smaller pieces for greater optimization.
  .splitEntryChunks()

  // Will require an extra script tag for runtime.js
  // But, you probably want this, unless you're building a single-page app
  .enableSingleRuntimeChunk()

  // FEATURE CONFIG
  .cleanupOutputBeforeBuild()
  .enableBuildNotifications()
  .enableSourceMaps(!Encore.isProduction())
  .enableVersioning(Encore.isProduction())

  // Enables @babel/preset-env polyfills
  .configureBabelPresetEnv((config) => {
    config.useBuiltIns = "usage";
    config.corejs = 3;
  })

  // Enables Sass/SCSS support
  //.enableSassLoader()

  // Uncomment if you use TypeScript
  //.enableTypeScriptLoader()

  // Uncomment to get integrity="..." attributes on your script & link tags
  //.enableIntegrityHashes(Encore.isProduction())

  // Uncomment if you're having problems with a jQuery plugin
  //.autoProvidejQuery()

  // Uncomment if you use API Platform Admin (composer req api-admin)
  .enableReactPreset();
// .addEntry('admin', './assets/js/admin.js')

// Configure DefinePlugin
Encore.configureDefinePlugin((options) => {
  options["process.env"] = {
    API_URL: JSON.stringify(process.env.API_URL),
  };
});

module.exports = Encore.getWebpackConfig();
