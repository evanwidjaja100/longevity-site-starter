# Design Tokens

## Content width
| Token | Value | Usage |
|---|---|---|
| `--wp--custom--measure--article` | `72ch` | Article body text max-width |
| `--wp--custom--measure--compact` | `60ch` | Introductory and summary text max-width |
| Wide width (theme.json layout) | `1140px` | Wide block alignment |

## Spacing scale
| Slug | Name | Size |
|---|---|---|
| `10` | 3XS | `0.25rem` |
| `20` | 2XS | `0.5rem` |
| `30` | XS | `0.75rem` |
| `40` | S | `1.25rem` |
| `50` | M | `2rem` |
| `60` | L | `3rem` |
| `70` | XL | `4.5rem` |
| `80` | 2XL | `6.5rem` |

## Type scale
| Slug | Size |
|---|---|
| `small` | `clamp(0.8rem, 1.2vw, 0.9rem)` |
| `medium` | `clamp(0.9rem, 1.3vw, 1.05rem)` |
| `large` | `clamp(1.15rem, 2.2vw, 1.5rem)` |
| `x-large` | `clamp(1.45rem, 3.2vw, 2.1rem)` |
| `xx-large` | `clamp(1.8rem, 4.5vw, 2.8rem)` |
| `display` | `clamp(2.5rem, 7vw, 5rem)` |

## Border radius
| Token | Value |
|---|---|
| `--wp--custom--radius--small` | `4px` |
| `--wp--custom--radius--card` | `8px` |
| `--wp--custom--radius--large` | `12px` |
| `--wp--custom--radius--pill` | `999px` |

## Border strength
- Component cards, trust blocks, source lists: `1px solid var(--wp--preset--color--border)`
- Test deviations, failures, warnings: `3px solid` left border
- Active focus ring: `3px solid var(--wp--preset--color--focus)`
- Print: all borders become `0.5pt solid black`

## Status colors
| Role | Token | Example |
|---|---|---|
| Brand / accent | `--wp--preset--color--brand` | Links, badges, accents |
| Brand subtle | `--wp--preset--color--brand-subtle` | Badge backgrounds |
| Border | `--wp--preset--color--border` | Card borders, dividers |
| Focus ring | `--wp--preset--color--focus` | Keyboard focus outline |
| Warning | `--wp--preset--color--warning` | Limitations, corrections |
| Warning subtle | `--wp--preset--color--warning-subtle` | Warning backgrounds |
| Muted text | `--wp--preset--color--muted` | Secondary metadata |
| Paper | `--wp--preset--color--paper` | Page background |
| Surface | `--wp--preset--color--surface` | Card and panel background |
| Base | `--wp--preset--color--base` | Primary text background |

## Focus ring
- All interactive elements: `3px solid var(--wp--preset--color--focus)`
- Offset: `3px`
- Transition: `outline-offset 0.1s ease`

## Print colors
- Backgrounds: `white` / `transparent` (overridden in `@media print`)
- Text: `black`
- Links: show URL in parentheses
- Borders: `0.5pt solid black`
- Shadows, gradients, animations: suppressed

## Table behavior
| Property | Value |
|---|---|
| Border collapse | `collapse` |
| Header style | Bottom border `2px` |
| Row separator | Top border `1px` |
| Hover | Background `var(--wp--preset--color--surface)` |
| Mobile overflow | `overflow-x: auto` with `-webkit-overflow-scrolling: touch` |
| Print | Break rows across pages with `break-inside: avoid` on wrappers |

## Cascade layers (introduced in Phase 7)
```
@layer tokens, reset, base, layout, components, utilities, overrides;
```
