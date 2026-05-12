---
name: laravel_medialibrary_setup
description: Installs and configures spatie/laravel-medialibrary for handling file uploads, conversions, and thumbnails.
category: general
created_by: agent
version: 1
is_active: true
source: database
dependencies: []
---

1. Run `composer require spatie/laravel-medialibrary`.
2. Publish the config and migration:
   ```bash
   php artisan vendor:publish --provider="Spatie\MediaLibrary\MediaLibraryServiceProvider" --tag="config"
   php artisan vendor:publish --provider="Spatie\MediaLibrary\MediaLibraryServiceProvider" --tag="migrations"
   ```
3. Run migrations: `php artisan migrate`.
4. In your model (e.g., `Post`), add the `HasMedia` interface and `InteractsWithMedia` trait:
   ```php
   use Spatie\MediaLibrary\HasMedia;
   use Spatie\MediaLibrary\InteractsWithMedia;
   class Post extends Model implements HasMedia {
       use InteractsWithMedia;
   }
   ```
5. Register any conversions in the model’s `registerMediaConversions` method (e.g., thumbnails).
6. Upload a file in a controller:
   ```php
   $post = Post::find($id);
   $post->addMedia($request->file('image'))->toMediaCollection('images');
   ```
7. Retrieve URLs:
   ```php
   $url = $post->getFirstMediaUrl('images');
   ```
8. Adjust config `config/medialibrary.php` for disk, fallback URL, etc.
9. Run the `laravel_shadcn_project` skill before installing this package.
