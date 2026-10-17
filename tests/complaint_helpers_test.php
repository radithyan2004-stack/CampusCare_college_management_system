<?php
declare(strict_types=1);

require __DIR__ . '/../app/complaint_helpers.php';

function check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$complaint = [
    'user_id' => 10,
    'assigned_admin_id' => 20,
    'department' => 'Science',
    'is_sensitive' => 0,
    'status' => 'In Progress',
    'first_responded_at' => null,
    'first_response_due_at' => date('Y-m-d H:i:s', time() - 60),
    'resolution_due_at' => date('Y-m-d H:i:s', time() + 3600),
];
$teacher = ['id' => 20, 'role' => 'teacher', 'department' => 'Arts'];
$studentOwner = ['id' => 10, 'role' => 'student'];
$otherStudent = ['id' => 11, 'role' => 'student'];
$principal = ['id' => 30, 'role' => 'principal'];
$admin = ['id' => 40, 'role' => 'admin'];

check(can_view_complaint($teacher, $complaint), 'Assigned teachers must see a complaint outside their department.');
check(can_view_complaint($studentOwner, $complaint), 'The complaint owner must see their complaint.');
check(!can_view_complaint($otherStudent, $complaint), 'Other students must not see the complaint.');
check(complaint_scope($teacher)[0] === 'c.is_sensitive=0 AND (c.assigned_admin_id=? OR c.department=?)', 'Teacher list scope must match complaint access policy.');
check(complaint_sla_state($complaint)['overdue'], 'An unanswered case past its response deadline must be overdue.');

$complaint['is_sensitive'] = 1;
check(can_view_complaint($admin, $complaint), 'Administrators must see private complaints.');
check(can_view_complaint($studentOwner, $complaint), 'Private complaint submitters must retain access.');
check(!can_view_complaint($teacher, $complaint), 'Teachers must not see private complaints, even when assigned.');
check(!can_view_complaint($principal, $complaint), 'Principals must not see private complaints.');

$complaint['status'] = 'Resolved';
check(!complaint_sla_state($complaint)['overdue'], 'Closed complaints must not remain overdue.');

echo "Complaint helper checks passed.\n";
