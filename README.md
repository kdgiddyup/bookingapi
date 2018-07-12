# bookingapi

Combined Beaufort and Lexington County booking data api

requests must include these URL parameters:

- `requested=lexington|beaufort`
- `start=[0 to 90]`
- `end=[0 to 90]`

where `requested` is either `lexington` or `beaufort`, `start` is an integer from 0 to 90 representing the start day as relative days before current date (0) and `end` is an integer from 0 to 90 representing the end day as relative days before current date (0).

**Example 1:** `?requested=beaufort&start=3&end=0` would return booking data from Beaufort County for the last 3 days.

**Example 2:** `?requested=lexington&start=60&end=30` would return booking data from Lexington County for between 60 and 30 days ago.
