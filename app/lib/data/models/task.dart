// A personal to-do item ("مهامي").

/// Task with a priority (مهم / مؤجل / عادي) and an optional reminder.
class Task {
  const Task({required this.id, required this.title, this.body, this.done, required this.pri, this.remindAt});

  final String id;
  final String title;
  final String? body;
  final bool? done;

  /// "مهم" | "مؤجل" | "عادي"
  final String pri;
  final String? remindAt;

  Map<String, Object?> toJson() => <String, Object?>{
    'id': id,
    'title': title,
    if (body != null) 'body': body,
    if (done != null) 'done': done,
    'pri': pri,
    if (remindAt != null) 'remindAt': remindAt,
  };

  factory Task.fromJson(Map<String, Object?> j) => Task(
    id: j['id'] as String? ?? '',
    title: j['title'] as String? ?? '',
    body: j['body'] as String?,
    done: j['done'] as bool?,
    pri: j['pri'] as String? ?? 'عادي',
    remindAt: j['remindAt'] as String?,
  );

  /// The same task marked done / not done.
  Task toggled() => Task(id: id, title: title, body: body, done: !(done ?? false), pri: pri, remindAt: remindAt);
}
