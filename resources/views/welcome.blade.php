<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import 5000 users</title>
    @vite('resources/css/app.css')
</head>
<body class="bg-gray-900 h-screen antialiased leading-none text-gray-300">

<div class="container mx-auto mt-20">
    <div class="bg-gray-800 p-6 rounded shadow-lg w-full md:w-1/2 mx-auto">
        <button id="importButton" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-full">
            Import Users
        </button>

        <div id="loadingIndicator" class="flex items-center justify-center mt-4 hidden">
            <div class="animate-spin rounded-full h-10 w-10 border-t-2 border-b-2 border-blue-500"></div>
            <span class="ml-3">Processing... Please wait.</span>
        </div>

        <div class="mt-8">
            <p class="mb-4"><strong>Added:</strong> <span id="addedUsers">0</span></p>
            <p class="mb-4"><strong>Total Users:</strong> <span id="totalUsers">Loading...</span></p>
            <p><strong>Updated:</strong> <span id="updatedUsers">0</span></p>
        </div>
    </div>
</div>

<script>
    let intervalId = null;

    const importButton = document.getElementById('importButton');
    const loadingIndicator = document.getElementById('loadingIndicator');

    const fetchImportResults = async () => {
        try {
            const resultResponse = await fetch('/api/job-results/user-import');
            const resultData = await resultResponse.json();

            if (resultData && resultData.total !== undefined) {
                document.getElementById('totalUsers').innerText = resultData.total;
                document.getElementById('addedUsers').innerText = resultData.added;
                document.getElementById('updatedUsers').innerText = resultData.updated;
                clearInterval(intervalId); // Stop polling once we have results
                loadingIndicator.classList.add('hidden');
                importButton.removeAttribute('disabled');
            }
        } catch (error) {
            console.error("Error fetching import results:", error);
        }
    };

    importButton.addEventListener('click', async () => {
        importButton.setAttribute('disabled', 'disabled');
        loadingIndicator.classList.remove('hidden');

        try {
            const response = await fetch('/import-users');
            if (!response.ok) {
                throw new Error('Server responded with a non-200 status');
            }

            // Start polling for results after initiating the import
            intervalId = setInterval(fetchImportResults, 5000); // Poll every 5 seconds
        } catch (error) {
            console.error("Error importing users:", error);
            loadingIndicator.classList.add('hidden');
            importButton.removeAttribute('disabled');
        }
    });
</script>
</body>
</html>
