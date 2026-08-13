# Workshop: Building a CRUD Feature in Laravel 13

This guide walks through building one complete CRUD "feature slice" in this
Laravel app, using **Category** as the running example. By the end you'll
have touched every layer Laravel expects you to touch for a resource:
migration → model → factory/seeder → form requests → controller → routes →
views.

The `Product` feature in this repo follows the exact same pattern and is a
little more involved (foreign key, more fields, search/filter). Once you've
built `Category` here, the workshop exercise at the end asks you to read
`Product`'s files cold and explain what each one does — that's the real test
of whether the pattern stuck.

## Prerequisites

- PHP 8.3+, Composer, Node/npm, and a MySQL/MariaDB server running.
- A clone of this repo with `composer install` and `npm install` already run.
- `.env` pointed at a real database (see README.md) and `php artisan migrate`
  run at least once.

Everything below assumes you're running commands from the project root.

## The mental model: a "feature slice"

Laravel doesn't force an architecture on you, but the default tooling
(`php artisan make:*`) nudges you toward one file per responsibility:

| Layer | File | Job |
|---|---|---|
| Migration | `database/migrations/..._create_categories_table.php` | Defines the table shape in the database |
| Model | `app/Models/Category.php` | Eloquent representation of a row + relationships |
| Factory | `database/factories/CategoryFactory.php` | Generates fake instances for seeding/testing |
| Seeder | `database/seeders/CategorySeeder.php` | Populates the database with real or fake starter data |
| Form Requests | `app/Http/Requests/Store...` / `Update...` | Validation rules, isolated from the controller |
| Controller | `app/Http/Controllers/CategoryController.php` | Orchestrates: reads input, talks to the model, picks a view |
| Routes | `routes/web.php` | Maps URLs + HTTP verbs to controller methods |
| Views | `resources/views/categories/*.blade.php` | What the user actually sees |

We'll build these in that order — bottom-up from the database, then up
through the HTTP layer, finishing with the UI.

---

## Step 1 — Migration

Generate a model *and* its migration/factory in one command:

```bash
php artisan make:model Category -mf
```

`-m` creates a migration, `-f` creates a factory. Open the generated
migration in `database/migrations/..._create_categories_table.php` and fill
in the `up()` method:

```php
public function up(): void
{
    Schema::create('categories', function (Blueprint $table) {
        $table->id();
        $table->string('name')->unique();
        $table->text('description')->nullable();
        $table->timestamps();
    });
}
```

Two decisions worth calling out:
- `->unique()` on `name` — enforced at the database level, not just in
  validation. Two independent safety nets are better than one.
- `description` is `nullable()` because the schema says so — a category
  doesn't need a description to be valid.

Run it:

```bash
php artisan migrate
```

If you mess up a column while iterating, `php artisan migrate:fresh` drops
every table and re-runs all migrations from scratch — much faster than
writing a new migration to fix a typo while you're still developing.

---

## Step 2 — Model

Open `app/Models/Category.php`. Laravel's generator gives you an empty
shell; you need to add:

1. `$fillable` — the allow-list of columns that can be mass-assigned via
   `Category::create($request->validated())`. Without this, Eloquent
   throws a `MassAssignmentException` for safety — it refuses to guess
   which fields are safe to fill from user input.
2. The relationship. A category `hasMany` products:

```php
class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
```

The method name (`products`) becomes how you access it everywhere else:
`$category->products`, `$category->products()->create(...)`,
`Category::with('products')`, etc. Laravel infers the foreign key
(`category_id`) from the class name — that's why the migration column is
named exactly `category_id` on the `products` table, not `cat_id` or
anything creative.

---

## Step 3 — Factory

A factory's only job is: given no input, produce a plausible fake row.
Open `database/factories/CategoryFactory.php`:

```php
public function definition(): array
{
    return [
        'name' => ucfirst($this->faker->unique()->words(2, true)),
        'description' => $this->faker->optional()->sentence(),
    ];
}
```

`->unique()` on the faker call matters here because `name` has a database
`unique()` constraint — without it, generating more than a handful of
categories will eventually collide and throw a `QueryException`.
`->optional()` means roughly 50% of generated categories get `null` for
description, which is a nice way to make your seeded data exercise the
`nullable` column instead of always having a value.

---

## Step 4 — Seeder

Factories generate *fake* data; seeders decide *what* to generate and
*how many*. For categories in a retail app, hardcoded realistic names read
better in a demo than random Latin. `database/seeders/CategorySeeder.php`:

```php
public function run(): void
{
    $categories = [
        ['name' => 'Beverages', 'description' => 'Soft drinks, juices, and bottled water.'],
        ['name' => 'Snacks', 'description' => 'Chips, biscuits, and packaged snacks.'],
        ['name' => 'Household', 'description' => 'Cleaning supplies and household essentials.'],
        ['name' => 'Electronics', 'description' => 'Small electronics and accessories.'],
        ['name' => 'Stationery', 'description' => 'Office and school supplies.'],
    ];

    foreach ($categories as $category) {
        Category::query()->create($category);
    }
}
```

Then wire it into `database/seeders/DatabaseSeeder.php` so `php artisan
migrate:fresh --seed` picks it up:

```php
$this->call([
    CategorySeeder::class,
    ProductSeeder::class,
]);
```

Order matters here: `ProductSeeder` needs categories to already exist so it
can attach products to them (more on that pitfall below).

> **Pitfall we hit building this repo:** `ProductFactory`'s definition
> defaults `category_id` to `Category::factory()`. If a seeder calls
> `Product::factory()->count(20)->make()` and then manually overwrites
> `category_id` before saving, Laravel *still silently creates* 20 extra
> throwaway categories in the database — because factory relationships
> resolve (and persist) as soon as the definition array is built, regardless
> of whether the *parent* model is `make()`'d or `create()`'d. The fix is
> `Product::factory()->count(20)->recycle(Category::all())->create()` —
> `recycle()` tells the factory "reuse one of these instead of making a new
> one" for any nested factory relationship. Worth knowing before you spend
> twenty minutes wondering why your categories table has 25 rows instead
> of 5.

---

## Step 5 — Form Requests

Validation rules don't belong inline in the controller — a `FormRequest`
class keeps them testable and reusable, and Laravel auto-validates before
your controller method even runs (a failed request never reaches your
code; it redirects back with errors in the session).

Generate both — you need separate classes for store vs. update because the
uniqueness rule differs (update must exclude the current row):

```bash
php artisan make:request StoreCategoryRequest
php artisan make:request UpdateCategoryRequest
```

`app/Http/Requests/StoreCategoryRequest.php`:

```php
public function authorize(): bool
{
    return true; // any authenticated user may create a category
}

public function rules(): array
{
    return [
        'name' => ['required', 'string', 'max:255', 'unique:categories,name'],
        'description' => ['nullable', 'string'],
    ];
}
```

`app/Http/Requests/UpdateCategoryRequest.php` — same rules, but the unique
check has to ignore the category being edited, otherwise saving a category
without changing its name would fail validation against itself:

```php
public function rules(): array
{
    return [
        'name' => [
            'required',
            'string',
            'max:255',
            Rule::unique('categories', 'name')->ignore($this->route('category')),
        ],
        'description' => ['nullable', 'string'],
    ];
}
```

`$this->route('category')` reads the `{category}` route-model-bound value
straight off the current request — no need to inject the model separately.

`authorize()` defaults to `false` when generated — an easy thing to forget
and then wonder why every request 403s. If a request has no per-user
authorization logic beyond "must be logged in" (which the route middleware
already handles), just return `true`.

---

## Step 6 — Controller

```bash
php artisan make:controller CategoryController --resource --model=Category
```

`--resource` scaffolds all seven RESTful methods
(`index/create/store/show/edit/update/destroy`); `--model` type-hints the
model so Laravel route-model-binds `Category $category` for you. We don't
need `show` (there's no category detail page in this app), so we'll leave
it out of the routes later rather than delete the method.

```php
class CategoryController extends Controller
{
    public function index(): View
    {
        $categories = Category::query()
            ->withCount('products')
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();

        return view('categories.index', compact('categories'));
    }

    public function create(): View
    {
        return view('categories.create');
    }

    public function store(StoreCategoryRequest $request): RedirectResponse
    {
        Category::query()->create($request->validated());

        return redirect()
            ->route('categories.index')
            ->with('success', 'Category created successfully.');
    }

    public function edit(Category $category): View
    {
        return view('categories.edit', compact('category'));
    }

    public function update(UpdateCategoryRequest $request, Category $category): RedirectResponse
    {
        $category->update($request->validated());

        return redirect()
            ->route('categories.index')
            ->with('success', 'Category updated successfully.');
    }

    public function destroy(Category $category): RedirectResponse
    {
        $category->delete();

        return redirect()
            ->route('categories.index')
            ->with('success', 'Category deleted successfully.');
    }
}
```

Notice what's *not* here: no manual validation, no manual "did this fail"
branching. Type-hinting `StoreCategoryRequest` instead of the generic
`Request` is what triggers Laravel to validate before the method body
runs — `$request->validated()` is guaranteed to only contain the fields
your `rules()` allowed through.

`withCount('products')` on the index query adds a `products_count`
attribute to each category in one extra query (not N+1 — it's a single
`GROUP BY` under the hood), which the index view uses to show how many
products are in each category.

---

## Step 7 — Routes

Resource routes fold all seven URLs into one line. We use `->except('show')`
since there's no detail page:

```php
Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('categories', CategoryController::class)->except('show');
});
```

That one line generates:

```
GET|HEAD   categories              categories.index
GET|HEAD   categories/create       categories.create
POST       categories              categories.store
GET|HEAD   categories/{category}/edit  categories.edit
PUT|PATCH  categories/{category}   categories.update
DELETE     categories/{category}   categories.destroy
```

Run `php artisan route:list --name=categories` any time you want to see
this for real instead of trusting the table above.

Putting the whole group behind `auth` + `verified` middleware means every
one of these routes 302-redirects to `/login` for a guest — that's the
entire "protect this feature behind auth" requirement, done in one line at
the routing layer rather than repeated per-controller.

---

## Step 8 — Views

Four Blade files, one directory: `resources/views/categories/`.

**`_form.blade.php`** — shared between create and edit, so the two forms
can never drift out of sync with each other:

```blade
<div>
    <x-input-label for="name" :value="__('Name')" />
    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full"
        :value="old('name', $category->name ?? '')" required autofocus />
    <x-input-error :messages="$errors->get('name')" class="mt-2" />
</div>

<div class="mt-4">
    <x-input-label for="description" :value="__('Description')" />
    <textarea id="description" name="description" rows="4"
        class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm">{{ old('description', $category->description ?? '') }}</textarea>
    <x-input-error :messages="$errors->get('description')" class="mt-2" />
</div>
```

Two patterns worth internalizing here because you'll use them constantly:

- `old('name', $category->name ?? '')` — after a failed validation
  redirect, `old()` repopulates whatever the user typed so they don't have
  to retype the whole form over one typo. The `$category->name ?? ''`
  fallback is what makes this same partial work for *both* create (no
  `$category` in scope) and edit (pre-fill from the existing row).
- `$errors->get('name')` — Laravel shares a `$errors` `ViewErrorBag` with
  every view automatically after a failed validation redirect; you never
  pass it in yourself.

**`create.blade.php`** wraps the partial in a form pointed at `store`:

```blade
<form method="POST" action="{{ route('categories.store') }}">
    @csrf
    @include('categories._form')
    <x-primary-button>{{ __('Save Category') }}</x-primary-button>
</form>
```

**`edit.blade.php`** is nearly identical, but points at `update` and adds
Laravel's method-spoofing field — browsers can't send `PUT` from an HTML
form, so Laravel fakes it via a hidden `_method` input that its routing
layer reads:

```blade
<form method="POST" action="{{ route('categories.update', $category) }}">
    @csrf
    @method('PUT')
    @include('categories._form')
    <x-primary-button>{{ __('Update Category') }}</x-primary-button>
</form>
```

**`index.blade.php`** is the table + pagination + delete button:

```blade
<form action="{{ route('categories.destroy', $category) }}" method="POST"
      onsubmit="return confirm('Delete this category? Its products will also be deleted.');">
    @csrf
    @method('DELETE')
    <button type="submit">{{ __('Delete') }}</button>
</form>
...
{{ $categories->links() }}
```

Same method-spoofing trick for `DELETE`. `{{ $categories->links() }}`
renders pagination controls for free because `->paginate(10)` in the
controller returned a `LengthAwarePaginator`, not a plain collection.

The delete confirm dialog calls out the cascade explicitly — deleting a
category cascades to delete its products too, because of
`->cascadeOnDelete()` on the migration's foreign key. That's a real,
destructive side effect a user should be warned about before clicking.

---

## Flash messages, for free

Every controller action above ends with `->with('success', '...')`. That
flashes a value into the *next* request's session. The layout renders it
once, globally, so no individual view needs to think about it:

`resources/views/components/flash-messages.blade.php`:

```blade
@if (session('success'))
    <div class="mb-4 rounded-md bg-green-50 ...">{{ session('success') }}</div>
@endif
```

...included once in `resources/views/layouts/app.blade.php`, above
`{{ $slot }}`. Add a category, and the redirect back to the index carries
the flash message with it — this is why the controller redirects instead
of just returning a view directly after `store()`/`update()`/`destroy()`.

---

## Checking your work

```bash
php artisan route:list --name=categories   # confirm all 6 routes exist
php artisan migrate:fresh --seed           # rebuild + reseed the database
php artisan serve                          # http://127.0.0.1:8000
```

Log in with `test@example.com` / `password`, click **Categories** in the
nav, and walk through: create one, try a duplicate name (should show a
validation error, not a crash), edit one, delete one (confirm its products
disappear too).

---

## Your turn: read `Product` cold

`app/Http/Controllers/ProductController.php`,
`app/Http/Requests/{Store,Update}ProductRequest.php`, and
`resources/views/products/*.blade.php` implement the identical pattern,
plus three things `Category` doesn't have to deal with. Before looking at
the code, guess how each is solved — then go check:

1. **A foreign key to another model.** How does the `category_id` field
   get validated, and how does the `<select>` in the form get its list of
   categories to choose from?
2. **A boolean checkbox (`is_active`).** Unchecked HTML checkboxes send
   *no value at all* — so how does unchecking "Active" and saving actually
   turn the value off in the database, instead of just leaving it
   untouched? (Hint: look for a `type="hidden"` input right before the
   checkbox in `products/_form.blade.php`.)
3. **Search and category filtering on the index page.** `ProductController@index`
   builds its query with `->when(...)` twice. What does each condition
   check, and what happens to the URL when you submit the filter form?

If you can explain those three answers in your own words, you've
internalized the pattern well enough to build a third resource
(`Supplier`? `Warehouse`?) from scratch using this same eight-step recipe.
