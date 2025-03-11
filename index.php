<?php
session_start();
require_once 'db.php';
require_once 'functions.php';

// Get filters and search
$selected_program = $_GET['program'] ?? null;
$status_filter = $_GET['status'] ?? 'scheduled';
$search = $_GET['search'] ?? '';
$is_modal = isset($_GET['modal']) && $_GET['modal'] == 'true';
// Fetch interviews
$interviews = getInterviews($conn, $selected_program, $status_filter, $search);

// Handle form submission for adding a new interview
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_interview'])) {
    $interviewer_name = $_POST['interviewer_name'];
    $interviewer_email = $_POST['interviewer_email'];
    $program_id = intval($_POST['program']);
    $applicant_name = $_POST['applicant_name'];
    $applicant_email = $_POST['applicant_email'];
    $scheduled_time = $_POST['scheduled_time'];

    // Validate scheduled time
    if (DateTime::createFromFormat('Y-m-d\TH:i', $scheduled_time) === false) {
        $error = "Invalid date and time format.";
    } else {
        // Get or create interviewer
        $interviewer_id = getOrCreateRecord($conn, 'interviewers', $interviewer_name, $interviewer_email, $program_id);
        // Get or create applicant
        $applicant_id = getOrCreateRecord($conn, 'applicants', $applicant_name, $applicant_email);

        // Insert interview
        if (insertInterview($conn, $applicant_id, $interviewer_id, $program_id, $scheduled_time)) {
            header("Location: index.php");
            exit();
        } else {
            $error = "Error adding interview: " . mysqli_error($conn);
        }
    }
}

/**
 * Get or create a record in the database (interviewer or applicant).
 */
function getOrCreateRecord($conn, $table, $name, $email, $program_id = null) {
    $stmt = $conn->prepare("SELECT id, name FROM $table WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        if ($row['name'] !== $name) {
            $stmt = $conn->prepare("UPDATE $table SET name = ? WHERE id = ?");
            $stmt->bind_param("si", $name, $row['id']);
            $stmt->execute();
        }
        return $row['id'];
    } else {
        $query = ($table === 'interviewers') 
            ? "INSERT INTO $table (name, email, program_id) VALUES (?, ?, ?)"
            : "INSERT INTO $table (name, email) VALUES (?, ?)";
        $stmt = $conn->prepare($query);
        if ($table === 'interviewers') {
            $stmt->bind_param("ssi", $name, $email, $program_id);
        } else {
            $stmt->bind_param("ss", $name, $email);
        }
        $stmt->execute();
        return $conn->insert_id;
    }
}

/**
 * Insert a new interview into the database.
 */
function insertInterview($conn, $applicant_id, $interviewer_id, $program_id, $scheduled_time) {
    $stmt = $conn->prepare("INSERT INTO interviews (applicant_id, interviewer_id, program_id, scheduled_time, status) VALUES (?, ?, ?, ?, 'scheduled')");
    $stmt->bind_param("iiis", $applicant_id, $interviewer_id, $program_id, $scheduled_time);
    return $stmt->execute();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Interview Scheduling</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* Modal Styles */
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5); animation: fadeIn 0.3s; }
        .modal-content { background-color: #fefefe; margin: 10% auto; padding: 20px; border: 1px solid #888; width: 90%; max-width: 500px; border-radius: 8px; box-shadow: 0 4px 8px rgba(0,0,0,0.1); animation: slideDown 0.3s; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes slideDown { from { transform: translateY(-50px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .close { color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer; }
        .close:hover, .close:focus { color: black; text-decoration: none; }

        /* Form Styles */
        .form-group { margin-bottom: 15px; }
        .form-control { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; background-color: #f9f9f9; }
        .btn-container { display: flex; justify-content: space-between; margin-top: 20px; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen p-6" style="background-image: linear-gradient(rgba(0, 100, 0, 0.5), rgba(0, 100, 0, 0.5)), url('assets/plv.jpeg'); background-size: cover; background-position: center; background-repeat: no-repeat;">

<div class="half-w-6xl w-full bg-white p-6 shadow-lg rounded-lg">
    <!-- Header -->
    <header class="border-b border-gray-300 pb-4">
        <h1 class="text-2xl font-bold text-green-700">Interview Scheduling</h1>
        <div class="flex items-center gap-4 mt-2">
            <button id="openModalBtn" class="px-4 py-2 bg-green-100 text-green-700 border border-green-600 rounded-full hover:bg-green-200 flex items-center justify-center gap-2">
                <span>Add Schedule</span><span>+</span>
            </button>
            <form id="searchForm" method="GET" action="index.php" class="flex items-center">
                <div class="relative rounded-full shadow-md">
                    <input type="text" name="search" class="rounded-full border border-gray-300 py-2 pl-5 pr-10 focus:outline-none w-64" placeholder="Search..." value="<?= htmlspecialchars($search) ?>">
                    <button type="submit" class="absolute right-0 top-0 h-full px-4 rounded-full bg-transparent hover:bg-gray-100 text-gray-600">
                        <img src="assets/search.png" alt="Search" class="w-5 h-5">
                    </button>
                </div>
            </form>
        </div>
    </header>

    <!-- Filters -->
    <div class="flex flex-col sm:flex-row justify-between items-center mt-4 mb-6 gap-4">
        <div class="flex gap-2">
            <select id="program" class="border rounded-lg px-3 py-2" onchange="updateFilter('program', this.value)">
                <option value="">All program</option>
                <option value="CBA" <?= ($selected_program == 'CBA') ? 'selected' : '' ?>>CBA</option>
                <option value="COT" <?= ($selected_program == 'COT') ? 'selected' : '' ?>>COT</option>
                <option value="COED" <?= ($selected_program == 'COED') ? 'selected' : '' ?>>COED</option>
            </select>
            <select id="status" class="border rounded-lg px-3 py-2" onchange="updateFilter('status', this.value)">
                <option value="scheduled" <?= ($status_filter == 'scheduled') ? 'selected' : '' ?>>Scheduled</option>
                <option value="cancelled" <?= ($status_filter == 'cancelled') ? 'selected' : '' ?>>Cancelled</option>
                <option value="trash" <?= ($status_filter == 'trash') ? 'selected' : '' ?>>Trash</option>
            </select>
        </div>
    </div>

    <!-- Table -->
    <div class="overflow-x-auto">
        <table class="w-full border-collapse bg-white shadow-md rounded-lg">
            <thead class="<?= ($status_filter == 'trash') ? 'bg-red-500 text-white' : 'bg-green-600 text-white'; ?>">
                <tr>
                    <th class="p-3 text-left" width="15%">Applicant Name</th>
                    <th class="p-3 text-center" width="30%"><?= ($status_filter == 'trash') ? 'Date Cancelled' : 'Date & Time'; ?></th>
                    <th class="p-3 text-center" width="20%">Faculty/Interviewer</th>
                    <th class="p-3 text-center" width="15%">Status</th>
                    <th class="p-3 text-center" width="20%">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($interviews)): ?>
                    <?php foreach ($interviews as $interview): ?>
                        <tr class="border-b hover:bg-gray-100">
                            <td class="p-3 font-medium text-black-700"><?= htmlspecialchars($interview['applicant'] ?? 'N/A'); ?></td>
                            <td class="p-3 text-center">
                                <div class="flex flex-col items-center">
                                    <?php if ($status_filter == 'trash'): ?>
                                        <span class="font-medium"><?= date('M d, Y', strtotime($interview['cancelled_date'] ?? 'now')); ?></span>
                                    <?php else: ?>
                                        <span class="font-medium"><?= date('M d, Y', strtotime($interview['scheduled_time'])); ?></span>
                                        <span class="text-gray-600">
                                            <?= date('h:i A', strtotime($interview['scheduled_time'])); ?> - 
                                            <?= date('h:i A', strtotime($interview['scheduled_time'] . ' +30 minutes')); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="p-3 font-medium text-center">
                                <span class="px-3 py-1 bg-green-50 text-black-800 rounded-full"><?= htmlspecialchars($interview['interviewer'] ?? 'N/A'); ?></span>
                            </td>
                            <td class="p-3 text-center">
                                <span class="px-3 py-1 rounded-full font-medium 
                                    <?= ($status_filter == 'trash') ? 'bg-red-100 text-red-700' : 
                                        (($interview['status'] === 'cancelled') ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700'); ?>">
                                    <?= ucfirst($interview['status'] ?? 'Unknown'); ?>
                                </span>
                            </td>
                            <td class="p-3">
                                <div class="flex justify-center space-x-2">
                                    <?php if ($status_filter === 'cancelled'): ?>
                                        <button onclick="moveToTrash(<?= $interview['id']; ?>)" class="p-2 bg-red-400 text-white rounded-lg hover:bg-red-500 flex items-center justify-center">
                                            <img src="assets/delete.png" alt="Delete" title="Delete" class="w-4 h-4">
                                        </button>
                                    <?php else: ?>
                                        <button onclick="cancelInterview(<?= $interview['id']; ?>)" class="p-2 bg-red-400 text-white rounded-lg hover:bg-red-500 flex items-center justify-center">
                                            <img src="assets/cancel.png" alt="Cancel" title="Cancel" class="w-4 h-4">
                                        </button>
                                    <?php endif; ?>
                                    <a href="edit_interview.php?id=<?= $interview['id']; ?>" class="p-2 bg-blue-400 text-white rounded-lg hover:bg-blue-500 flex items-center justify-center">
                                        <img src="assets/edit.png" alt="Edit" title="Edit" class="w-4 h-4">
                                    </a>
                                    <a href="view_interview.php?id=<?= $interview['id']; ?>" class="p-2 bg-green-400 text-white rounded-lg hover:bg-green-500 flex items-center justify-center">
                                        <img src="assets/view.png" alt="View" title="View" class="w-4 h-4">
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="text-center text-gray-500 p-3">No interviews found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add Schedule Modal -->
<div id="addScheduleModal" class="modal">
    <div class="modal-content" style="max-width: 450px; padding: 15px;">
        <span class="close">&times;</span>
        <div class="bg-green-100 text-center py-1 rounded-t-lg mb-3">
            <h2 class="text-lg font-semibold text-black">New Interview</h2>
        </div>
        <?php if(isset($error)): ?>
            <div class="bg-red-100 text-red-700 p-2 my-2 rounded"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form action="index.php" method="POST" class="space-y-3">
            <input type="hidden" name="add_interview" value="1">
            <div class="form-group" style="margin-bottom: 10px;">
                <label for="applicant_name" class="block text-sm font-medium text-gray-700">Applicant Name</label>
                <input type="text" id="applicant_name" name="applicant_name" required class="form-control" style="padding: 6px;">
            </div>
            <div class="form-group" style="margin-bottom: 10px;">
                <label for="applicant_email" class="block text-sm font-medium text-gray-700">Applicant Email</label>
                <input type="email" id="applicant_email" name="applicant_email" required class="form-control" style="padding: 6px;">
            </div>
            <div class="form-group" style="margin-bottom: 10px;">
                <label for="scheduled_time" class="block text-sm font-medium text-gray-700">Date & Time</label>
                <input type="datetime-local" id="scheduled_time" name="scheduled_time" required class="form-control" style="padding: 6px;">
            </div>
            <div class="form-group" style="margin-bottom: 10px;">
                <label for="program" class="block text-sm font-medium text-gray-700">Program</label>
                <select id="program-select" name="program" required class="form-control" style="padding: 6px;">
                    <option value="" disabled selected>Select program</option>
                    <option value="1">COT</option>
                    <option value="2">COED</option>
                    <option value="3">CBA</option>
                </select>
            </div>
            <div class="form-group" style="margin-bottom: 10px;">
                <label for="interviewer_name" class="block text-sm font-medium text-gray-700">Interviewer Name</label>
                <input type="text" id="interviewer_name" name="interviewer_name" required class="form-control" style="padding: 6px;">
            </div>
            <div class="form-group" style="margin-bottom: 10px;">
                <label for="interviewer_email" class="block text-sm font-medium text-gray-700">Interviewer Email</label>
                <input type="email" id="interviewer_email" name="interviewer_email" required class="form-control" style="padding: 6px;">
            </div>
            <div class="btn-container" style="margin-top: 12px;">
                <button type="button" class="close-modal px-3 py-1 bg-red-100 text-red-700 rounded-md hover:bg-red-200 font-semibold">Cancel</button>
                <button type="submit" class="px-3 py-1 bg-green-700 text-white rounded-md hover:bg-green-800 font-semibold">Confirm</button>
            </div>
        </form>
    </div>
</div>

<script>
    // JavaScript for filters, modal, and cancel functionality
function updateFilter(type, value) {
    const url = new URL(window.location.href);
    url.searchParams.set(type, value);
    window.location.href = url.toString();
}

function cancelInterview(interviewId) {
    if (confirm("Are you sure you want to cancel this interview?")) {
        window.location.href = `cancel_interview.php?id=${interviewId}`;
    }
}

function moveToTrash(interviewId) {
    if (confirm("Are you sure you want to move this interview to trash? You can still restore it if you want to.")) {
        fetch(`trash_interview.php?id=${interviewId}`, {
            method: 'GET'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Interview moved to trash successfully!');
                window.location.reload();
            } else {
                alert('Error: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Request failed.');
        });
    }
}

// Modal functionality
const modal = document.getElementById("addScheduleModal");
const openBtn = document.getElementById("openModalBtn");
const closeBtn = document.querySelector(".close");
const closeBtns = document.querySelectorAll(".close-modal");

// Improved modal functions
function openModal(modalElement) {
    modalElement.style.display = "block";
    document.body.style.overflow = "hidden";
    
    // Add a class to disable pointer events on the background content
    document.querySelector('.half-w-6xl').classList.add('pointer-events-none');
}

function closeModal(modalElement) {
    modalElement.style.display = "none";
    document.body.style.overflow = "auto";
    
    // Re-enable pointer events on the background content
    document.querySelector('.half-w-6xl').classList.remove('pointer-events-none');
}

// Event handlers for add schedule modal
openBtn.onclick = () => openModal(modal);
closeBtn.onclick = () => closeModal(modal);
closeBtns.forEach(btn => btn.onclick = () => closeModal(modal));

document.addEventListener('keydown', (event) => { 
    if (event.key === "Escape") {
        if (modal.style.display === "block") closeModal(modal);
        if (document.getElementById("editInterviewModal").style.display === "block") closeEditModal();
        if (document.getElementById("viewInterviewModal").style.display === "block") closeViewModal();
    }
});

// Create modals for edit and view
document.body.insertAdjacentHTML('beforeend', `
<!-- Edit Interview Modal -->
<div id="editInterviewModal" class="modal">
    <div class="modal-content" style="max-width: 450px; padding: 15px;">
        <span class="close" onclick="closeEditModal()">&times;</span>
        <div class="bg-green-100 text-center py-1 rounded-t-lg mb-3">
            <h2 class="text-lg font-semibold text-black">Edit Interview</h2>
        </div>
        <div id="editModalContent">
            <!-- Content will be loaded here -->
            <div class="text-center py-4">Loading...</div>
        </div>
    </div>
</div>

<!-- View Interview Modal -->
<div id="viewInterviewModal" class="modal">
    <div class="modal-content" style="max-width: 450px; padding: 15px;">
        <span class="close" onclick="closeViewModal()">&times;</span>
        <div class="bg-green-100 text-center py-1 rounded-t-lg mb-3">
            <h2 class="text-lg font-semibold text-black">View Interview</h2>
        </div>
        <div id="viewModalContent">
            <!-- Content will be loaded here -->
            <div class="text-center py-4">Loading...</div>
        </div>
    </div>
</div>
`);

// Function to open the edit modal
function openEditModal(interviewId) {
    const modal = document.getElementById("editInterviewModal");
    const modalContent = document.getElementById("editModalContent");
    
    // Show the modal and disable background
    openModal(modal);
    
    // Fetch the edit form content
    fetch(`edit_interview.php?id=${interviewId}&modal=true`)
        .then(response => response.text())
        .then(data => {
            // Insert the form into the modal
            modalContent.innerHTML = data;
            
            // Add event listener to the form
            const form = modalContent.querySelector('form');
            if (form) {
                form.addEventListener('submit', function(event) {
                    event.preventDefault();
                    
                    const formData = new FormData(this);
                    
                    fetch("update_interview.php", {
                        method: "POST",
                        body: formData
                    })
                    .then(response => response.text())
                    .then(data => {
                        if (data.trim() === "success") {
                            alert("Interview updated successfully!");
                            closeEditModal();
                            window.location.reload(); // Reload the page to show updated data
                        } else {
                            alert("Error: " + data);
                        }
                    })
                    .catch(error => {
                        console.error("Error:", error);
                        alert("An error occurred while updating the interview.");
                    });
                });
            }
        })
        .catch(error => {
            console.error("Error:", error);
            modalContent.innerHTML = `<div class="text-red-500 py-4">Error loading content</div>`;
        });
}

// Function to close the edit modal
function closeEditModal() {
    const modal = document.getElementById("editInterviewModal");
    closeModal(modal);
}

// Function to open the view modal
function openViewModal(interviewId) {
    const modal = document.getElementById("viewInterviewModal");
    const modalContent = document.getElementById("viewModalContent");
    
    // Show the modal and disable background
    openModal(modal);
    
    // Fetch the view content
    fetch(`view_interview.php?id=${interviewId}&modal=true`)
        .then(response => response.text())
        .then(data => {
            // Insert the content into the modal
            modalContent.innerHTML = data;
        })
        .catch(error => {
            console.error("Error:", error);
            modalContent.innerHTML = `<div class="text-red-500 py-4">Error loading content</div>`;
        });
}

// Function to close the view modal
function closeViewModal() {
    const modal = document.getElementById("viewInterviewModal");
    closeModal(modal);
}



// Update the click handlers in the table
document.addEventListener('DOMContentLoaded', function() {
    // Add pointer-events-none class for background content when modal is active
    const style = document.createElement('style');
    style.textContent = `
        .pointer-events-none {
            pointer-events: none !important;
        }
    `;
    document.head.appendChild(style);
    
    // Get all edit buttons and update their click handlers
    const editButtons = document.querySelectorAll('a[href^="edit_interview.php"]');
    editButtons.forEach(button => {
        const href = button.getAttribute('href');
        const id = href.split('=')[1];
        
        button.setAttribute('href', 'javascript:void(0)');
        button.setAttribute('onclick', `openEditModal(${id})`);
    });
    
    // Get all view buttons and update their click handlers
    const viewButtons = document.querySelectorAll('a[href^="view_interview.php"]');
    viewButtons.forEach(button => {
        const href = button.getAttribute('href');
        const id = href.split('=')[1];
        
        button.setAttribute('href', 'javascript:void(0)');
        button.setAttribute('onclick', `openViewModal(${id})`);
    });
});

document.getElementById('searchForm').addEventListener('keypress', function (event) {
    if (event.key === 'Enter') {
        event.preventDefault();
        this.submit();
    }
});
</script>

</body>
</html>