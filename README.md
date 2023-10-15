Controller Explanation

    importUsers: This is the primary function that initiates the user import process. It fetches users, updates or adds them, and then returns a JSON response with a summary.

    fetchUsersInBatches: Responsible for fetching users from the API in batches for efficiency.

    updateOrAddUsers: Filters the fetched users, separates them into ones to be inserted and ones to be updated, then performs the necessary database operations.

    bulkUpdate & bulkInsert: Functions to handle the bulk database operations efficiently.

    extractUserData: Extracts and returns necessary user data from the fetched data or returns null if essential data is missing.

For a deep dive into the code, refer to UserController.php in the Controllers directory.
