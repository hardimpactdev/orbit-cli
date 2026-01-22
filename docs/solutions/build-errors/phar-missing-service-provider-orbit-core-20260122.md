---
date: 2026-01-22
problem_type: build_error
component: orbit-cli/phar-build
severity: critical
symptoms:
  - "PHP Fatal error: Uncaught Error: Class \"HardImpact\\Orbit\\OrbitServiceProvider\" not found"
  - "Error occurs in phar:///path/to/orbit.phar/vendor/laravel/framework/src/Illuminate/Foundation/Application.php:961"
  - "PHAR runs but crashes immediately on startup"
  - "Works fine when running from source with php orbit"
root_cause: orbit-core package not being included properly in PHAR build
tags: [phar, laravel-zero, service-provider, orbit-core]
---

# PHAR Build Missing orbit-core Service Provider

## Symptom
After building orbit.phar with Laravel Zero's build process, the PHAR fails on startup with:

```
PHP Fatal error:  Uncaught Error: Class "HardImpact\Orbit\OrbitServiceProvider" not found in phar:///home/nckrtl/projects/orbit-cli/builds/orbit.phar/vendor/laravel/framework/src/Illuminate/Foundation/Application.php:961
```

The error occurs when `DatabaseServiceProvider` tries to register `OrbitServiceProvider::class`.

## Investigation
1. Attempted: Verified orbit-core files exist in vendor/
   Result: Files are present at `vendor/hardimpactdev/orbit-core/src/OrbitServiceProvider.php`

2. Attempted: Built PHAR after updating to orbit-core 0.0.5
   Result: Same error persists

3. Attempted: Checked box.json configuration
   Result: Configuration includes vendor directory correctly

## Root Cause
Laravel Zero has orbit-core listed in composer.json's `extra.laravel.dont-discover` array, preventing automatic service provider discovery. When the PHAR is built, the package files may be included but the autoloader doesn't properly register the namespace.

## Solution
**Temporary Workaround**: Use development installation instead of PHAR:

```bash
# Install from source
cd ~/projects/orbit-cli
cp orbit ~/.local/bin/orbit
chmod +x ~/.local/bin/orbit

# This runs PHP directly, not the PHAR
orbit --version
```

**What We've Tried**:
1. ✅ Removed orbit-core from `dont-discover` array in composer.json
2. ✅ Removed manual registration of OrbitServiceProvider in DatabaseServiceProvider
3. ❌ Still fails with "Call to undefined method Psr\Log\NullLogger::channel()"

**Permanent Fix** (needs investigation):
1. Investigate Laravel Zero's PHAR build process for external packages
2. May need custom Box hooks to properly include orbit-core
3. Consider bundling orbit-core directly in orbit-cli instead of as a package
4. Research Laravel Zero community solutions for similar issues

## Prevention
- Test PHAR builds whenever updating critical dependencies
- Include PHAR smoke test in CI/CD pipeline:
  ```bash
  ./builds/orbit.phar --version
  ./builds/orbit.phar sites
  ```
- Document which packages require special handling in PHAR builds
- Consider using GitHub Actions to build and test PHARs automatically

## Related
- [Laravel Zero PHAR Building Documentation](https://laravel-zero.com/docs/build-a-standalone-application)
- [Box.json Configuration Reference](https://github.com/box-project/box/blob/master/doc/configuration.md)
- Similar issues:
  - Laravel packages with service providers often have PHAR issues
  - Packages that use package discovery may need manual registration