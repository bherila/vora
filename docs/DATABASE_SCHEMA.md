# K-1 Flow Database Schema

## Overview

K1 Flow uses a relational database to track Schedule K-1 forms and related tax information. This document describes the database schema and relationships.

## Entity Relationship Diagram

```
┌─────────────────┐
│  k1_companies   │
│─────────────────│
│ id (PK)         │
│ name            │
│ ein             │
│ entity_type     │
│ address         │
│ city, state, zip│
└────────┬────────┘
         │
         │ 1:many
         ▼
┌─────────────────┐      ┌───────────────────────┐
│    k1_forms     │      │  ownership_interests  │
│─────────────────│      │───────────────────────│
│ id (PK)         │      │ id (PK)               │
│ company_id (FK) │      │ owner_company_id (FK) │
│ tax_year        │      │ owned_company_id (FK) │
│ [K-1 fields]    │      │ ownership_percentage  │
└───────┬─────────┘      │ inception_basis_*     │
        │                └───────────┬───────────┘
        │ 1:many                     │
        ▼                            │ 1:many
┌──────────────────┐                 ▼
│k1_income_sources │      ┌───────────────────────┐
│──────────────────│      │    outside_basis      │
│ k1_form_id (FK)  │      │───────────────────────│
│ income_type      │      │ ownership_interest_id │
│ amount           │      │ tax_year              │
└──────────────────┘      │ beginning_ob          │
                          │ ending_ob             │
                          └───────────┬───────────┘
                                      │
                                      │ 1:many
                                      ▼
                          ┌───────────────────────┐
                          │   ob_adjustments      │
                          │───────────────────────│
                          │ outside_basis_id (FK) │
                          │ adjustment_category   │
                          │ adjustment_type_code  │
                          │ amount                │
                          │ document_*            │
                          └───────────────────────┘
```

## Tables

### k1_companies
Stores information about partnerships, S-corps, and other pass-through entities that issue K-1 forms.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| name | varchar(255) | Company name |
| ein | varchar(20) | Employer Identification Number |
| entity_type | varchar(50) | e.g., Partnership, S-Corp, LLC |
| address | varchar(255) | Street address |
| city | varchar(100) | City |
| state | varchar(2) | State code |
| zip | varchar(20) | ZIP code |
| notes | text | Additional notes |

### k1_forms
Stores Schedule K-1 forms with all IRS-defined fields from Parts I, II, and III.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| company_id | bigint | FK to k1_companies |
| tax_year | int | Tax year (e.g., 2024) |
| form_file_path | varchar | Path to uploaded PDF |
| form_file_name | varchar | Original filename |
| partnership_* | various | Part I fields |
| partner_* | various | Part II fields |
| share_of_* | decimal(8,4) | Ownership percentages |
| *_liabilities | decimal(16,2) | Box K liability amounts |
| *_capital_account | decimal(16,2) | Box L capital account |
| box_1 through box_22 | various | Part III income/deduction items |

### k1_income_sources
Categorizes income by type for loss limitation purposes.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| k1_form_id | bigint | FK to k1_forms |
| income_type | enum | passive, non_passive, capital, trade_or_business_461l |
| description | varchar | Description |
| amount | decimal(16,2) | Amount |
| k1_box_reference | varchar | Reference to K-1 box (e.g., "Box 1") |

### k1_outside_basis → outside_basis
Tracks partner's outside basis in the partnership interest, now linked to ownership_interests with tax_year.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| ownership_interest_id | bigint | FK to ownership_interests |
| tax_year | int | Tax year this basis record applies to |
| beginning_ob | decimal(16,2) | Beginning of year OB (auto-calculated from prior year) |
| ending_ob | decimal(16,2) | Manual override for End of year OB (if null, calculated dynamically) |
| notes | text | Additional notes |

### k1_ob_adjustments → ob_adjustments
CPA work product for annual basis adjustments with predefined adjustment types.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| outside_basis_id | bigint | FK to outside_basis |
| adjustment_category | enum | 'increase' or 'decrease' |
| adjustment_type_code | varchar(50) | Predefined type code (see below) |
| adjustment_type | varchar(100) | Custom description for 'other' types |
| amount | decimal(16,2) | Adjustment amount |
| description | text | Additional details |
| document_path | varchar | Path to supporting document |
| document_name | varchar | Original filename of document |
| sort_order | int | Display order |

**Predefined Increase Type Codes:**
- `cash_contribution` - Cash contributions
- `property_contribution` - Property contributions (FMV)
- `increase_liabilities` - Increase in share of partnership liabilities
- `assumption_personal_liabilities` - Partnership assumption of personal liabilities
- `share_income` - Share of partnership income/gain
- `tax_exempt_income` - Tax-exempt income
- `excess_depletion` - Excess depletion (oil & gas)
- `other_increase` - Other increase (use adjustment_type for description)

**Predefined Decrease Type Codes:**
- `cash_distribution` - Cash distributions
- `property_distribution` - Property distributions (basis)
- `decrease_liabilities` - Decrease in share of partnership liabilities
- `personal_liabilities_assumed` - Personal liabilities assumed by partnership
- `share_losses` - Share of partnership losses
- `nondeductible_noncapital` - Nondeductible expenses (not capitalized)
- `section_179` - Section 179 deduction
- `depletion_deduction` - Oil & gas depletion deduction
- `other_decrease` - Other decrease (use adjustment_type for description)

### k1_loss_carryforwards → loss_carryforwards
Tracks suspended losses by type and character, now linked to ownership_interests.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| ownership_interest_id | bigint | FK to ownership_interests |
| origin_year | int | Year loss originated |
| carryforward_type | enum | at_risk, passive, excess_business_loss, nol |
| source_ebl_year | int | For NOL type: the year the EBL originated (EBL Year N → NOL Year N+1) |
| loss_character | varchar | Loss character: ORD (Ordinary) or CAP (Capital) |
| original_amount | decimal(16,2) | Original loss amount |
| remaining_amount | decimal(16,2) | Remaining suspended amount |
| notes | text | Additional notes |

### k1_ownership → ownership_interests
Tracks ownership relationships for tiered structures, with inception basis info.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| owner_company_id | bigint | FK to k1_companies (owner), nullable for individual |
| owned_company_id | bigint | FK to k1_companies (owned entity) |
| ownership_percentage | decimal(14,11) | Percentage ownership with high precision |
| effective_from | date | Start of ownership period |
| effective_to | date | End of ownership period (null = current) |
| ownership_class | varchar | e.g., Class A, Common, Preferred |
| inception_date | date | Full date the interest was acquired |
| inception_basis_year | int | Year the interest was acquired (legacy, derived from inception_date) |
| method_of_acquisition | varchar(50) | Method: purchase, gift, inheritance, compensation, contribution |
| inheritance_date | date | Date of death for inherited interests |
| cost_basis_inherited | decimal(16,2) | Stepped-up basis (FMV at death) for inheritance |
| gift_date | date | Date of gift for gifted interests |
| gift_donor_basis | decimal(16,2) | Donor's carryover basis for gifts |
| gift_fmv_at_transfer | decimal(16,2) | FMV at time of gift |
| contributed_cash_property | decimal(16,2) | Cash/property contribution |
| purchase_price | decimal(16,2) | Purchase price if acquired |
| gift_inheritance | decimal(16,2) | Basis from gift/inheritance (legacy) |
| taxable_compensation | decimal(16,2) | Compensatory interest value |
| inception_basis_total | decimal(16,2) | Total inception basis |
| notes | text | Additional notes |

**Method of Acquisition Values:**
- `purchase` - Acquired via purchase from another party
- `gift` - Received as a gift (carryover basis rules apply)
- `inheritance` - Inherited (stepped-up basis rules apply)
- `compensation` - Received as taxable compensation (profits interest, carried interest)
- `contribution` - Initial contribution of cash/property to partnership

### loss_limitations
Tracks loss limitation calculations under IRS rules, per ownership interest per year.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| ownership_interest_id | bigint | FK to ownership_interests |
| tax_year | int | Tax year |
| capital_at_risk | decimal(16,2) | Form 6198 at-risk amount |
| at_risk_deductible | decimal(16,2) | Deductible at-risk loss |
| at_risk_carryover | decimal(16,2) | Suspended at-risk loss |
| passive_activity_loss | decimal(16,2) | Form 8582 passive loss |
| passive_loss_allowed | decimal(16,2) | Allowed passive loss |
| passive_loss_carryover | decimal(16,2) | Suspended passive loss |
| excess_business_loss | decimal(16,2) | Section 461(l) EBL |
| excess_business_loss_carryover | decimal(16,2) | Suspended EBL (becomes NOL next year) |
| nol_deduction_used | decimal(16,2) | NOL deduction used in current year |
| nol_carryforward | decimal(16,2) | NOL carryforward remaining after current year |
| nol_80_percent_limit | decimal(16,2) | 80% limitation for post-2017 NOLs |
| notes | text | Additional notes |

## Loss Limitation Ordering (IRS Rules)

Losses flow through limitations in a specific order per IRS rules:

| Order | Limitation | Scope | Key References |
|-------|-----------|-------|---------------|
| 0 | Deductibility check | Transaction | Is it deductible at all? (hobby, personal use) |
| 1 | Character & basket rules | Return-level | Capital loss limitations (Schedule D) |
| 2 | Basis limitation | Per entity | §704(d) partnership; §1366(d) S corp |
| 3 | At-risk limitation | Per activity | §465 (Form 6198) |
| 4 | Passive activity loss | Per activity | §469 (Form 8582) |
| 5 | Excess Business Loss | Aggregate | §461(l) (Form 461) |
| 6 | NOL computation | Cross-year | §172 - EBL carryover becomes NOL |
| 7 | 80% NOL limitation | Year of use | Post-2017 NOLs limited to 80% of taxable income |

### EBL to NOL Conversion
- Excess Business Loss (EBL) disallowed in Year N under §461(l)
- This EBL carryover becomes an NOL carryforward starting Year N+1
- Track with `carryforward_type = 'nol'` and `source_ebl_year = N`

## Foreign Key Relationships

All child tables cascade on delete from their parent:
- k1_forms → k1_companies
- k1_income_sources → k1_forms
- ownership_interests → k1_companies (both owner and owned)
- outside_basis → ownership_interests
- ob_adjustments → outside_basis
- loss_limitations → ownership_interests
- loss_carryforwards → ownership_interests

## Money Field Convention

All monetary amounts use `DECIMAL(16,2)` for precision:
- 16 total digits, 2 decimal places
- Supports values up to $99,999,999,999,999.99
- Negative values allowed for losses

## Date Handling Convention

### Backend to Frontend Date Serialization

Laravel models use the `SerializesDatesAsLocal` trait which serializes dates in `YYYY-MM-DD HH:mm:ss` format (e.g., `"2024-01-15 00:00:00"`). This avoids timezone shifting issues in JavaScript.

### HTML Date Input Compatibility

HTML `<input type="date">` elements require dates in strict `YYYY-MM-DD` format. When populating date inputs from API data, use `DateHelper.toInputDate()` to convert:

```typescript
import { DateHelper } from '@/lib/DateHelper';

// In a React component:
const [date, setDate] = useState('');

useEffect(() => {
  // Convert Laravel datetime to input format
  setDate(DateHelper.toInputDate(apiData.inception_date));
}, [apiData]);
```

**Supported Input Formats:**
- `YYYY-MM-DD` (returned as-is)
- `YYYY-MM-DD HH:mm:ss` (Laravel default)
- `YYYY-MM-DD HH:mm:ss.SSS` (with milliseconds)
- `YYYY-MM-DDTHH:mm:ss` (ISO 8601)
- Various other formats via `parseDate()` fallback

**Unit Tests:** See `tests-ts/DateHelper.test.ts` for comprehensive test coverage.

## Basis Walk Feature

The Basis Walk provides a year-over-year view of outside basis tracking:

### Workflow

1. **Add New Ownership Interest**
   - User creates ownership interest with inception basis information
   - System generates basis walk table starting from inception year

2. **Basis Walk Table**
   - Shows all years from inception to current
   - Columns: Tax Year, Starting Basis, Adjustments, Ending Basis
   - Starting basis = prior year's ending basis (or inception basis for first year)
   - Adjustments = sum of increases minus decreases
   - Ending basis = Starting basis + Adjustments (unless manually overridden)

3. **Yearly Adjustment Entry**
   - User clicks on adjustments column to view/edit details
   - Predefined adjustment types with dropdown selection
   - Support for custom "other" adjustments with description
   - File attachment support for documentation

### Predefined Adjustment Types

**Increases:**
- Cash contributions
- Property contributions (FMV)
- Increase in share of partnership liabilities
- Partnership assumption of personal liabilities
- Share of partnership income/gain
- Tax-exempt income
- Excess depletion (oil & gas)
- Other increase

**Decreases:**
- Cash distributions
- Property distributions (basis)
- Decrease in share of partnership liabilities
- Personal liabilities assumed by partnership
- Share of partnership losses
- Nondeductible expenses (not capitalized)
- Section 179 deduction
- Oil & gas depletion deduction
- Other decrease
