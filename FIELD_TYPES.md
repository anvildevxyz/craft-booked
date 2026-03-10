# Field Types

Booked provides two custom field types that let you relate Services and Event Dates to any element with a field layout (entries, categories, etc.). Authors can then curate which bookable items appear on specific pages.

## Available Field Types

| Field Type | Relates To | Use Case |
|------------|-----------|----------|
| **Booked Services** | Service elements | "Feature these services on this page" |
| **Booked Event Dates** | Event Date elements | "Show these events on this page" |

Both are standard Craft relation fields — they behave exactly like the built-in Entries or Categories fields.

## Setup

### 1. Create the Field

1. Go to **Settings → Fields**
2. Click **New field**
3. Choose **Booked Services** or **Booked Event Dates** as the field type
4. Configure sources, min/max limits, and view mode as needed
5. Save

### 2. Add to a Field Layout

Add the field to any entry type, category group, or other element's field layout.

### 3. Use in Templates

Authors can now select Services or Event Dates when editing entries.

## Templating

### Basic Usage

```twig
{# Loop through related services #}
{% for service in entry.myServicesField.all() %}
  <div>
    <h3>{{ service.title }}</h3>
    <p>{{ service.duration }} min — {{ service.price|number }}</p>
  </div>
{% endfor %}

{# Loop through related event dates #}
{% for event in entry.myEventsField.all() %}
  <div>
    <h3>{{ event.title }}</h3>
    <p>{{ event.eventDate|date('M j, Y') }}</p>
  </div>
{% endfor %}
```

### Single Element

```twig
{# Get just the first related service #}
{% set service = entry.myServicesField.one() %}
{% if service %}
  <p>Featured: {{ service.title }}</p>
{% endif %}
```

### Eager Loading

```twig
{# Eager load to avoid N+1 queries #}
{% set entries = craft.entries
  .section('services')
  .with(['myServicesField'])
  .all() %}

{% for entry in entries %}
  {% for service in entry.myServicesField.all() %}
    {{ service.title }}
  {% endfor %}
{% endfor %}
```

### Reverse Relations

```twig
{# Find all entries that reference a specific service #}
{% set entries = craft.entries
  .relatedTo(service)
  .all() %}
```

### Conditional Queries

```twig
{# Only get enabled services with a specific duration #}
{% set services = entry.myServicesField
  .duration(60)
  .all() %}
```

## GraphQL

The fields automatically expose typed element arrays in GraphQL:

```graphql
{
  entries(section: "services") {
    title
    ... on services_pages_Entry {
      myServicesField {
        title
        duration
        price
      }
      myEventsField {
        title
        eventDate
        capacity
      }
    }
  }
}
```

## Field Settings

Both field types support all standard Craft relation field settings:

| Setting | Description |
|---------|-------------|
| **Sources** | Which element sources authors can select from |
| **Min Relations** | Minimum number of related elements |
| **Max Relations** | Maximum number of related elements |
| **View Mode** | How elements display (list, cards, etc.) |
| **Selection Label** | Custom label for the "Add" button |
| **Validate Related Elements** | Whether to validate related elements on save |

## Project Config

Fields are stored in `config/project/fields/` as YAML. Example:

```yaml
handle: featuredServices
name: Featured Services
searchable: false
settings:
  maxRelations: 5
  minRelations: null
  selectionLabel: 'Choose a service'
  sources: '*'
  viewMode: list
type: anvildev\booked\fields\BookedServices
```
