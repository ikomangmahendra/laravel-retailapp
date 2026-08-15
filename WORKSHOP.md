# Workshop: Building a CRUD Feature in Laravel 13

This guide walks through building one complete CRUD "feature slice" in this
Laravel app, using **Category** as the running example. By the end you'll
have touched every layer Laravel expects you to touch for a resource:
migration → model → factory/seeder → form requests → controller → routes →
views.

Once you've built `Category`, Part Two walks through `Product` — the same
eight steps again, but with the three things `Category` doesn't have to
deal with: a foreign key to another model, a boolean checkbox, and a
search/filter query on the index page. By the end of Part Two you'll have
built two complete resources.

Part Three then exposes both resources over a token-authenticated JSON
API alongside the existing Blade UI — the same models and form requests,
a different transport — and walks through a real bug this app shipped
with along the way. The closing exercise asks you to build a third
resource entirely on your own, web and API included.

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

Order matters here: `ProductSeeder` (Part Two) needs categories to already
exist so it can attach products to them — that's why `CategorySeeder` is
listed first.

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

## Checking your work (Part One)

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

# Part Two: Product — a feature slice with a foreign key

Same eight steps, same order. This time the model has a relationship to
another table, a boolean flag, and the index page needs search and
filtering — three things that come up in almost every real-world CRUD
screen, so it's worth seeing how each is handled once, carefully.

## Step 1 — Migration

```bash
php artisan make:model Product -mf
```

`database/migrations/..._create_products_table.php`:

```php
public function up(): void
{
    Schema::create('products', function (Blueprint $table) {
        $table->id();
        $table->foreignId('category_id')->constrained()->cascadeOnDelete();
        $table->string('sku')->unique();
        $table->string('name');
        $table->decimal('purchase_price', 12, 2);
        $table->decimal('selling_price', 12, 2);
        $table->integer('stock')->default(0);
        $table->string('unit');
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });
}
```

`foreignId('category_id')->constrained()` is shorthand for "add an
unsigned big integer column named `category_id`, and add a foreign key
constraint against the `id` column of whatever table its name implies" —
Laravel infers `categories` from `category_id` the same way it inferred
the relationship method earlier. `->cascadeOnDelete()` is the database-level
enforcement of "deleting a category deletes its products" — it's not
application code, it's a constraint MySQL itself enforces, so it holds
even if a product gets deleted through `mysql` directly instead of through
this app.

`decimal('purchase_price', 12, 2)` — money should almost never be a
`float`/`double` column, because binary floating point can't represent
every decimal fraction exactly and rounding errors compound. `decimal(12, 2)`
stores exactly 2 digits after the decimal point with up to 12 digits
total, and MySQL does the arithmetic in fixed-point, not floating-point.

## Step 2 — Model

```php
class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'sku',
        'name',
        'purchase_price',
        'selling_price',
        'stock',
        'unit',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'purchase_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'stock' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
```

Two new things compared to `Category`:

- **`casts()`** tells Eloquent how to convert database values into PHP
  types when you read the attribute, and back when you write it. Without
  the `boolean` cast, `is_active` would come back from MySQL as the string
  `"1"` or `"0"` — which is truthy in PHP either way, silently breaking any
  `if ($product->is_active)` check on an inactive product. Without the
  `decimal:2` cast, floating-point columns can print with binary rounding
  artifacts (`19.989999999999998` instead of `19.99`) once you start doing
  arithmetic on them.
- **`belongsTo`** is the inverse of `Category`'s `hasMany`. The convention
  is symmetrical: `belongsTo(Category::class)` looks for a `category_id`
  column on *this* model's own table (`products`) — no argument needed,
  because Laravel derives both the method name and the column name from
  it the same way as before.

## Step 3 — Factory

```php
public function definition(): array
{
    $purchasePrice = $this->faker->randomFloat(2, 5, 500);

    return [
        'category_id' => Category::factory(),
        'sku' => strtoupper($this->faker->unique()->bothify('SKU-####??')),
        'name' => ucfirst($this->faker->words(3, true)),
        'purchase_price' => $purchasePrice,
        'selling_price' => round($purchasePrice * $this->faker->randomFloat(2, 1.1, 1.6), 2),
        'stock' => $this->faker->numberBetween(0, 200),
        'unit' => $this->faker->randomElement(['pcs', 'box', 'kg', 'pack']),
        'is_active' => $this->faker->boolean(90),
    ];
}
```

`'category_id' => Category::factory()` is how a factory expresses "this
column is a foreign key — generate (or reuse) a related model for it."
Deriving `selling_price` from `purchase_price` with a random markup
(instead of two independent random numbers) is a small touch that makes
fake data look real: a retail catalog where the selling price is
*unrelated* to the purchase price would look obviously fake in a demo.
`$this->faker->boolean(90)` means a 90% chance of `true` — most seeded
products should be active, with a handful inactive to exercise that state.

## Step 4 — Seeder

```php
public function run(): void
{
    Product::factory()
        ->count(20)
        ->recycle(Category::all())
        ->create();
}
```

> **Pitfall we hit building this repo:** the first version of this seeder
> called `Product::factory()->count(20)->make()`, then manually overwrote
> `category_id` on each instance with a random existing category ID before
> saving — the intent being "don't let the factory create its own throwaway
> categories, reuse the ones from `CategorySeeder`." It didn't work: even
> though the *product* was only `make()`'d (not yet saved), the *nested*
> `Category::factory()` inside `ProductFactory`'s `definition()` still
> resolved and **persisted** a brand-new category to the database the
> moment the definition array was built — regardless of whether the parent
> model was ultimately saved or not. Running the seeder left 25 rows in
> `categories` instead of 5.
>
> The fix is `recycle(Category::all())`: it tells the factory "wherever
> your definition would create a new related model via `Category::factory()`,
> reuse one of these existing instances instead." Any time a factory's
> `definition()` references another model's factory, ask yourself whether
> you actually want a *fresh* related row every time, or whether you want
> to spread new records across an *existing* set — `recycle()` is how you
> ask for the latter.

## Step 5 — Form Requests

```bash
php artisan make:request StoreProductRequest
php artisan make:request UpdateProductRequest
```

`app/Http/Requests/StoreProductRequest.php`:

```php
public function rules(): array
{
    return [
        'category_id' => ['required', 'integer', 'exists:categories,id'],
        'sku' => ['required', 'string', 'max:255', 'unique:products,sku'],
        'name' => ['required', 'string', 'max:255'],
        'purchase_price' => ['required', 'numeric', 'min:0'],
        'selling_price' => ['required', 'numeric', 'min:0'],
        'stock' => ['required', 'integer', 'min:0'],
        'unit' => ['required', 'string', 'max:50'],
        'is_active' => ['boolean'],
    ];
}
```

`'exists:categories,id'` is the validation-layer counterpart to the
migration's `->constrained()` — it rejects a submitted `category_id` that
doesn't correspond to a real row, with a friendly form error, *before* the
database's foreign key constraint would have thrown a much uglier
`QueryException`. `min:0` on `purchase_price`/`selling_price`/`stock` is
exactly the schema requirement from the spec — Laravel's `numeric` and
`integer` rules stop non-numbers early, and `min:0` stops negative values.

`UpdateProductRequest` is identical except the `sku` uniqueness rule
ignores the current product, exactly like `name` did for
`UpdateCategoryRequest`:

```php
Rule::unique('products', 'sku')->ignore($this->route('product')),
```

## Step 6 — Controller

The three new methods worth reading closely are all in `index()`:

```php
public function index(Request $request): View
{
    $search = $request->string('search')->trim()->toString();
    $categoryId = $request->integer('category_id');

    $products = Product::query()
        ->with('category')
        ->when($search !== '', function ($query) use ($search) {
            $query->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            });
        })
        ->when($categoryId, fn ($query) => $query->where('category_id', $categoryId))
        ->orderBy('name')
        ->paginate(10)
        ->withQueryString();

    $categories = Category::query()->orderBy('name')->get();

    return view('products.index', compact('products', 'categories', 'search', 'categoryId'));
}
```

- **`->with('category')`** eager-loads the related category for every
  product in *one* extra query. Leave it out and the index view's
  `{{ $product->category->name }}` would fire one additional query *per
  row* on the page — the classic N+1 problem.
- **`->when($condition, $callback)`** only applies the callback if
  `$condition` is truthy — this is how "search" and "filter" become
  optional instead of always-required. When `$search` is empty, that whole
  `where` clause is skipped entirely rather than matching `LIKE '%%'`
  (which would technically also match everything, but skipping it is
  clearer and cheaper).
- **`->withQueryString()`** on the paginator is why clicking "page 2"
  doesn't lose your search term or category filter — it appends the
  current request's query string (`?search=...&category_id=...`) to every
  pagination link it generates.

## Step 7 — Routes

No new routing concept here — `Route::resource('products',
ProductController::class)->except('show')` sits right next to the
categories line in the same `auth`+`verified` group from Part One. Worth
noticing, though: one line of routing code serves both `/products` (no
query string) and `/products?search=foo&category_id=3` — query string
parameters were never part of route *definition*, only something the
controller reads off the `Request` object at runtime.

## Step 8 — Views

The `_form.blade.php` partial has the two new field types:

```blade
<select id="category_id" name="category_id" required>
    <option value="">{{ __('Select a category') }}</option>
    @foreach ($categories as $category)
        <option value="{{ $category->id }}"
            @selected(old('category_id', $product->category_id ?? '') == $category->id)>
            {{ $category->name }}
        </option>
    @endforeach
</select>
```

Same `old(...) ?? ''` fallback pattern as `Category`'s form, just applied
to a `<select>` instead of a text input — `@selected(...)` is Blade's
shorthand for conditionally printing the `selected` attribute.

```blade
<input type="hidden" name="is_active" value="0" />
<input type="checkbox" id="is_active" name="is_active" value="1"
    @checked(old('is_active', $product->is_active ?? true))
    ... />
```

This hidden-input-before-checkbox pairing is a standard HTML forms trick,
not Laravel-specific: browsers only include a checkbox's `name`/`value` in
the submitted form data **if it's checked** — an unchecked box sends
nothing at all. If the hidden input weren't there, unchecking "Active" and
saving would leave `is_active` completely absent from the request, and
`$request->validated()` would never touch the column — the database value
would just silently stay whatever it was before. The hidden input
guarantees `is_active=0` is always sent as a fallback; if the checkbox
*is* checked, its own `is_active=1` comes after it in the HTML and wins
(the browser sends both, and the last one for a given name is what Laravel
reads).

`products/index.blade.php` adds the actual search/filter controls as a
plain `GET` form:

```blade
<form method="GET" action="{{ route('products.index') }}">
    <input type="text" name="search" value="{{ $search }}" placeholder="Search by name or SKU..." />
    <select name="category_id">
        <option value="">{{ __('All Categories') }}</option>
        @foreach ($categories as $category)
            <option value="{{ $category->id }}" @selected($categoryId === $category->id)>{{ $category->name }}</option>
        @endforeach
    </select>
    <button type="submit">{{ __('Filter') }}</button>
</form>
```

It's deliberately a `GET` form, not `POST` — filtering/searching is read-only,
so it belongs in the URL (`?search=...`), which makes the result
bookmarkable and shareable, and is exactly the query string
`->withQueryString()` was preserving through pagination above.

## Checking your work (Part Two)

```bash
php artisan migrate:fresh --seed   # 5 categories, 20 products
php artisan serve
```

Log in, click **Products**, and check: search for a real SKU from the
seeded data, filter by a category, page through results with a filter
active (the filter should survive to page 2), create a product with a
negative price (should be rejected), edit a product and uncheck "Active"
(should actually save as inactive — this is the hidden-input trick from
above actually working).

---

# Part Three: Adding a token-based API with Sanctum

Parts One and Two built a browser-facing app: session cookies, CSRF
tokens, Blade views. A lot of real clients — a mobile app, another
service, a `curl` script — can't (and shouldn't have to) carry a
browser session. This part adds a second, parallel way to reach the same
`Category`/`Product` data: a JSON API authenticated with a bearer token,
living entirely alongside the existing web routes rather than replacing
them.

The new pieces:

| Layer | File | Job |
|---|---|---|
| Package | [`laravel/sanctum`](https://github.com/laravel/sanctum) | Issues/validates personal access tokens |
| Migration | `database/migrations/..._create_personal_access_tokens_table.php` | Stores hashed tokens, one row per issued token |
| Model trait | `HasApiTokens` on `app/Models/User.php` | Adds `createToken()` / `currentAccessToken()` to `User` |
| Auth controller | `app/Http/Controllers/Api/AuthController.php` | Issues a token on login, revokes it on logout |
| Routes | `routes/api.php` | Maps `/api/*` URLs to API controllers, guarded by `auth:sanctum` |
| API controllers | `app/Http/Controllers/Api/CategoryController.php` / `ProductController.php` | Same query logic as the web controllers, but return JSON |
| API resources | `app/Http/Resources/CategoryResource.php` / `ProductResource.php` | Shape the JSON response consistently |

Notice what's reused rather than rebuilt: `StoreCategoryRequest`,
`UpdateCategoryRequest`, `StoreProductRequest`, and `UpdateProductRequest`
from Parts One and Two work unchanged here — validation rules don't care
whether the response on failure is a redirect-with-errors or a 422 JSON
body, so there was no reason to duplicate them.

## Installing Sanctum

```bash
composer require laravel/sanctum
php artisan install:api
```

`install:api` does three things in one go: publishes
`config/sanctum.php`, generates and runs the
`create_personal_access_tokens_table` migration, and registers
`routes/api.php` in `bootstrap/app.php`:

```php
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // ...
```

It also tells you, in its own output, to add one line to `User` yourself:

```php
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;
    // ...
}
```

Without this trait, `$user->createToken(...)` and
`$request->user()->currentAccessToken()` simply don't exist — Sanctum's
token machinery is opt-in per model, not global.

## Step 1 — Login and logout

`app/Http/Controllers/Api/AuthController.php`:

```php
public function login(Request $request): JsonResponse
{
    $credentials = $request->validate([
        'email' => ['required', 'email'],
        'password' => ['required', 'string'],
    ]);

    $user = User::query()->where('email', $credentials['email'])->first();

    if (! $user || ! Hash::check($credentials['password'], $user->password)) {
        throw ValidationException::withMessages([
            'email' => ['The provided credentials are incorrect.'],
        ]);
    }

    $token = $user->createToken($request->userAgent() ?? 'api-token')->plainTextToken;

    return response()->json(['user' => $user, 'token' => $token]);
}

public function logout(Request $request): JsonResponse
{
    $request->user()->currentAccessToken()->delete();

    return response()->json(['message' => 'Logged out successfully.']);
}
```

A few things worth noticing:

- This checks the password by hand with `Hash::check()` instead of
  `Auth::attempt()`, because `Auth::attempt()` starts a *session* — the
  whole point here is a stateless request that ends with a token, not a
  cookie.
- `createToken()`'s argument is just a human-readable label stored
  alongside the token (visible in the `personal_access_tokens` table) —
  passing the User-Agent string means you can tell which device/client
  issued which token later.
- `plainTextToken` is only ever available once, at creation time. Sanctum
  stores a *hash* of the token in the database, the same way passwords
  are hashed — if you don't hand it back to the client in this response,
  it's gone for good.
- `currentAccessToken()` on logout deletes *only* the token used to
  authenticate this specific request, not every token the user has —
  logging out on one device shouldn't log you out everywhere.

## Step 2 — Routes

```php
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', fn (Request $request) => $request->user());
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::apiResource('categories', CategoryController::class)->names('api.categories');
    Route::apiResource('products', ProductController::class)->names('api.products');
});
```

`Route::apiResource` is `Route::resource` minus `create`/`edit` — an API
client doesn't need a route that returns an HTML form, only the five that
exchange data (`index`/`store`/`show`/`update`/`destroy`).

> **A real bug this app shipped with:** the first version of this file
> wrote plain `Route::apiResource('categories', ...)` and
> `Route::apiResource('products', ...)`, no `->names(...)`. That looks
> harmless, but `Route::resource('categories', ...)` in `routes/web.php`
> (Part One) and `Route::apiResource('categories', ...)` here generate
> **identically named routes** — both produce `categories.index`,
> `categories.store`, and so on. Route names have to be unique across the
> *entire* application, not per file, and Laravel doesn't warn you when
> one registration silently overwrites another.
>
> Because `bootstrap/app.php` registers `api:` after `web:`, the API
> routes won — every `route('categories.index')` call anywhere in the
> app, including the ones in `resources/views/layouts/navigation.blade.php`,
> started resolving to `/api/categories` instead of `/categories`.
> Clicking **Categories** in the nav sent a logged-in browser to a route
> guarded by `auth:sanctum` (which only understands bearer tokens, not
> session cookies), so the response came back "Unauthenticated" even
> though the user was clearly logged in.
>
> The fix is `->names('api.categories')` / `->names('api.products')`:
> it renames the whole group of generated routes to
> `api.categories.index`, `api.categories.store`, etc., so they can no
> longer collide with the web resource's names. The general lesson:
> whenever a second route group exposes the *same resource name* as an
> existing one — API alongside web being the most common case — give one
> of them an explicit name prefix rather than trusting the defaults not
> to collide.

## Step 3 — API controllers and resources

`app/Http/Controllers/Api/CategoryController.php` runs the *same* query
as the web `CategoryController`, but returns a `CategoryResource` instead
of a `view()`:

```php
public function index(Request $request): JsonResponse
{
    $categories = Category::query()
        ->withCount('products')
        ->orderBy('name')
        ->paginate(10)
        ->withQueryString();

    return CategoryResource::collection($categories)->response();
}
```

`app/Http/Resources/CategoryResource.php` is the JSON equivalent of a
Blade view — it decides exactly which fields go out over the wire and in
what shape, instead of leaking whatever columns happen to be on the
Eloquent model:

```php
public function toArray(Request $request): array
{
    return [
        'id' => $this->id,
        'name' => $this->name,
        'description' => $this->description,
        'products_count' => $this->whenCounted('products'),
        'created_at' => $this->created_at,
        'updated_at' => $this->updated_at,
    ];
}
```

`whenCounted('products')` only includes `products_count` in the response
when the query actually ran `withCount('products')` — calling
`CategoryResource::make($category)` from `show()` without a
`withCount()` first just omits the key instead of returning `null` or
throwing. `ProductResource` does the same trick with `whenLoaded('category')`
for its nested category, mirroring the `->with('category')` eager-load
from Part Two's controller.

Separate API controllers (rather than making the existing web
controllers return JSON when `Accept: application/json` is sent) is a
deliberate choice here: the web controllers are typed to return
`View`/`RedirectResponse`, and their `store`/`update`/`destroy` methods
redirect with flash messages — behavior an API client has no use for.
Duplicating the *query* logic (a handful of lines) was cheaper than
branching every method on response format.

## Checking your work (Part Three)

```bash
php artisan route:list --path=api   # confirm /api/login plus the guarded routes
php artisan serve
```

```bash
# 1. Log in, capture the token
curl -X POST http://127.0.0.1:8000/api/login \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"email":"test@example.com","password":"password"}'

# 2. Use it — replace <token> with the value from step 1
curl http://127.0.0.1:8000/api/categories \
  -H "Authorization: Bearer <token>" -H "Accept: application/json"

# 3. Confirm a missing/expired token is rejected
curl -i http://127.0.0.1:8000/api/categories -H "Accept: application/json"   # expect 401

# 4. Log out, then confirm the same token no longer works
curl -X POST http://127.0.0.1:8000/api/logout \
  -H "Authorization: Bearer <token>" -H "Accept: application/json"
curl -i http://127.0.0.1:8000/api/categories \
  -H "Authorization: Bearer <token>" -H "Accept: application/json"   # expect 401
```

Then, separately, log in through the *browser* and click **Categories**
in the nav — this is the regression check for the route-name bug above.
If it ever comes back (e.g. a fourth resource gets an unqualified
`apiResource` name that collides with its web counterpart), this is
where you'd see it: a page that should render normally instead shows
"Unauthenticated."

---

## Your turn: build a third resource on your own

Everything above is one recipe, applied twice, then exposed a second way.
Pick a third resource this retail app doesn't have yet — `Supplier`
(`name`, `contact_email`, `phone`) is a reasonable scope, or invent your
own — and build it end to end without copying an existing file
line-for-line:

1. `php artisan make:model Supplier -mf`, fill in the migration.
2. Add `$fillable` and any relationships to the model.
3. Fill in the factory's `definition()`.
4. Write (or skip) a seeder.
5. Generate `Store`/`UpdateSupplierRequest` with real validation rules.
6. Generate a resource controller; wire up `index`/`create`/`store`/`edit`/`update`/`destroy`.
7. Add one line to `routes/web.php` inside the existing `auth`+`verified` group.
8. Build `index`/`create`/`edit`/`_form` views, reusing the Blade components
   (`x-input-label`, `x-text-input`, `x-input-error`, `x-primary-button`)
   you've now seen twice.
9. Add the API side from Part Three: an `Api\SupplierController` +
   `SupplierResource` reusing the same `Store`/`UpdateSupplierRequest`
   from step 5, and an `apiResource` line in `routes/api.php` inside the
   `auth:sanctum` group — remember to `->names('api.suppliers')` so it
   doesn't collide with the web resource's route names from step 7.

If you get through all nine steps without opening `CategoryController.php`
or `ProductController.php` (or their `Api\` counterparts) for reference,
the pattern has stuck.
