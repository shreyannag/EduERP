<?php
// docker/seed_coding.php
// Seeds starter coding problems and test cases

require_once __DIR__ . '/../config/db.php';

$problems = [
    [
        'title' => 'Sum of Two Numbers',
        'slug' => 'sum-of-two-numbers',
        'difficulty' => 'Easy',
        'total_points' => 100,
        'description' => '<p>Given two integers $A$ and $B$, write a program to calculate and display their sum.</p><p>Standard input will supply the two numbers separated by whitespace.</p>',
        'input_format' => 'Two integers $A$ and $B$ separated by whitespace.',
        'output_format' => 'A single integer representing $A + B$.',
        'constraints' => '$-10^9 \le A, B \le 10^9$',
        'sample_input' => "15 27",
        'sample_output' => "42",
        'sample_explanation' => "15 + 27 equals 42.",
        'starter_code_python' => "import sys\n\ndef main():\n    data = sys.stdin.read().split()\n    if not data:\n        return\n    a = int(data[0])\n    b = int(data[1])\n    print(a + b)\n\nif __name__ == '__main__':\n    main()\n",
        'starter_code_java' => "import java.util.Scanner;\n\npublic class Solution {\n    public static void main(String[] args) {\n        Scanner sc = new Scanner(System.in);\n        if (sc.hasNextLong()) {\n            long a = sc.nextLong();\n            long b = sc.nextLong();\n            System.out.println(a + b);\n        }\n    }\n}\n",
        'starter_code_c' => "#include <stdio.h>\n\nint main() {\n    long long a, b;\n    if (scanf(\"%lld %lld\", &a, &b) == 2) {\n        printf(\"%lld\\n\", a + b);\n    }\n    return 0;\n}\n",
        'starter_code_cpp' => "#include <iostream>\n\nusing namespace std;\n\nint main() {\n    ios_base::sync_with_stdio(false);\n    cin.tie(NULL);\n    long long a, b;\n    if (cin >> a >> b) {\n        cout << (a + b) << \"\\n\";\n    }\n    return 0;\n}\n",
        'test_cases' => [
            ['input' => "15 27", 'output' => "42", 'sample' => 1, 'hidden' => 0, 'points' => 20],
            ['input' => "-5 12", 'output' => "7", 'sample' => 1, 'hidden' => 0, 'points' => 20],
            ['input' => "0 0", 'output' => "0", 'sample' => 0, 'hidden' => 1, 'points' => 20],
            ['input' => "1000000 2000000", 'output' => "3000000", 'sample' => 0, 'hidden' => 1, 'points' => 20],
            ['input' => "-999999999 999999999", 'output' => "0", 'sample' => 0, 'hidden' => 1, 'points' => 20],
        ]
    ],
    [
        'title' => 'Reverse a String',
        'slug' => 'reverse-a-string',
        'difficulty' => 'Easy',
        'total_points' => 100,
        'description' => '<p>Given a non-empty string $S$, reverse the string and print the result.</p>',
        'input_format' => 'A single line containing the string $S$.',
        'output_format' => 'Print the reversed string.',
        'constraints' => '$1 \le |S| \le 1000$',
        'sample_input' => "CodePlatform",
        'sample_output' => "mroftalPedoC",
        'sample_explanation' => "Reversing \"CodePlatform\" gives \"mroftalPedoC\".",
        'starter_code_python' => "import sys\n\ndef main():\n    s = sys.stdin.read().strip()\n    print(s[::-1])\n\nif __name__ == '__main__':\n    main()\n",
        'starter_code_java' => "import java.util.Scanner;\n\npublic class Solution {\n    public static void main(String[] args) {\n        Scanner sc = new Scanner(System.in);\n        if (sc.hasNextLine()) {\n            String s = sc.nextLine().trim();\n            System.out.println(new StringBuilder(s).reverse().toString());\n        }\n    }\n}\n",
        'starter_code_c' => "#include <stdio.h>\n#include <string.h>\n\nint main() {\n    char s[1024];\n    if (fgets(s, sizeof(s), stdin)) {\n        int len = strlen(s);\n        while (len > 0 && (s[len-1] == '\\n' || s[len-1] == '\\r')) len--;\n        for (int i = len - 1; i >= 0; i--) {\n            putchar(s[i]);\n        }\n        putchar('\\n');\n    }\n    return 0;\n}\n",
        'starter_code_cpp' => "#include <iostream>\n#include <string>\n#include <algorithm>\n\nusing namespace std;\n\nint main() {\n    string s;\n    if (getline(cin, s)) {\n        while (!s.empty() && (s.back() == '\\r' || s.back() == '\\n')) s.pop_back();\n        reverse(s.begin(), s.end());\n        cout << s << \"\\n\";\n    }\n    return 0;\n}\n",
        'test_cases' => [
            ['input' => "CodePlatform", 'output' => "mroftalPedoC", 'sample' => 1, 'hidden' => 0, 'points' => 25],
            ['input' => "radar", 'output' => "radar", 'sample' => 0, 'hidden' => 1, 'points' => 25],
            ['input' => "Antigravity AI 2026", 'output' => "6202 IA ytivargitnA", 'sample' => 0, 'hidden' => 1, 'points' => 25],
            ['input' => "A", 'output' => "A", 'sample' => 0, 'hidden' => 1, 'points' => 25],
        ]
    ],
    [
        'title' => 'Palindrome Number',
        'slug' => 'palindrome-number',
        'difficulty' => 'Easy',
        'total_points' => 100,
        'description' => '<p>Given an integer $N$, determine if it is a palindrome. An integer is a palindrome when it reads the same forward and backward.</p><p>Print <code>YES</code> if it is a palindrome, and <code>NO</code> otherwise.</p>',
        'input_format' => 'A single integer $N$.',
        'output_format' => 'Print <code>YES</code> or <code>NO</code>.',
        'constraints' => '$-2^{31} \le N \le 2^{31} - 1$',
        'sample_input' => "121",
        'sample_output' => "YES",
        'sample_explanation' => "121 reads 121 from left to right and right to left.",
        'starter_code_python' => "import sys\n\ndef main():\n    s = sys.stdin.read().strip()\n    if not s:\n        return\n    if s == s[::-1]:\n        print(\"YES\")\n    else:\n        print(\"NO\")\n\nif __name__ == '__main__':\n    main()\n",
        'starter_code_java' => "import java.util.Scanner;\n\npublic class Solution {\n    public static void main(String[] args) {\n        Scanner sc = new Scanner(System.in);\n        if (sc.hasNext()) {\n            String s = sc.next().trim();\n            String rev = new StringBuilder(s).reverse().toString();\n            if (s.equals(rev)) {\n                System.out.println(\"YES\");\n            } else {\n                System.out.println(\"NO\");\n            }\n        }\n    }\n}\n",
        'starter_code_c' => "#include <stdio.h>\n#include <string.h>\n\nint main() {\n    char s[64];\n    if (scanf(\"%63s\", s) == 1) {\n        int len = strlen(s);\n        int is_pal = 1;\n        for (int i = 0; i < len / 2; i++) {\n            if (s[i] != s[len - 1 - i]) {\n                is_pal = 0;\n                break;\n            }\n        }\n        printf(is_pal ? \"YES\\n\" : \"NO\\n\");\n    }\n    return 0;\n}\n",
        'starter_code_cpp' => "#include <iostream>\n#include <string>\n\nusing namespace std;\n\nint main() {\n    string s;\n    if (cin >> s) {\n        bool ok = true;\n        int n = s.size();\n        for (int i = 0; i < n / 2; i++) {\n            if (s[i] != s[n - 1 - i]) { ok = false; break; }\n        }\n        cout << (ok ? \"YES\" : \"NO\") << \"\\n\";\n    }\n    return 0;\n}\n",
        'test_cases' => [
            ['input' => "121", 'output' => "YES", 'sample' => 1, 'hidden' => 0, 'points' => 20],
            ['input' => "-121", 'output' => "NO", 'sample' => 1, 'hidden' => 0, 'points' => 20],
            ['input' => "10", 'output' => "NO", 'sample' => 0, 'hidden' => 1, 'points' => 20],
            ['input' => "12321", 'output' => "YES", 'sample' => 0, 'hidden' => 1, 'points' => 20],
            ['input' => "7", 'output' => "YES", 'sample' => 0, 'hidden' => 1, 'points' => 20],
        ]
    ],
    [
        'title' => 'FizzBuzz Sequence',
        'slug' => 'fizzbuzz-sequence',
        'difficulty' => 'Easy',
        'total_points' => 100,
        'description' => '<p>Given an integer $N$, for each integer $i$ from 1 to $N$:</p><ul><li>If $i$ is divisible by both 3 and 5, print <code>FizzBuzz</code></li><li>If $i$ is divisible by 3, print <code>Fizz</code></li><li>If $i$ is divisible by 5, print <code>Buzz</code></li><li>Otherwise, print $i$</li></ul>',
        'input_format' => 'A single positive integer $N$.',
        'output_format' => 'Print each result on a new line.',
        'constraints' => '$1 \le N \le 100$',
        'sample_input' => "5",
        'sample_output' => "1\n2\nFizz\n4\nBuzz",
        'sample_explanation' => "1, 2 are numbers. 3 is multiple of 3 (Fizz). 4 is number. 5 is multiple of 5 (Buzz).",
        'starter_code_python' => "import sys\n\ndef main():\n    data = sys.stdin.read().split()\n    if not data:\n        return\n    n = int(data[0])\n    for i in range(1, n + 1):\n        if i % 15 == 0:\n            print(\"FizzBuzz\")\n        elif i % 3 == 0:\n            print(\"Fizz\")\n        elif i % 5 == 0:\n            print(\"Buzz\")\n        else:\n            print(i)\n\nif __name__ == '__main__':\n    main()\n",
        'starter_code_java' => "import java.util.Scanner;\n\npublic class Solution {\n    public static void main(String[] args) {\n        Scanner sc = new Scanner(System.in);\n        if (sc.hasNextInt()) {\n            int n = sc.nextInt();\n            for (int i = 1; i <= n; i++) {\n                if (i % 15 == 0) System.out.println(\"FizzBuzz\");\n                else if (i % 3 == 0) System.out.println(\"Fizz\");\n                else if (i % 5 == 0) System.out.println(\"Buzz\");\n                else System.out.println(i);\n            }\n        }\n    }\n}\n",
        'starter_code_c' => "#include <stdio.h>\n\nint main() {\n    int n;\n    if (scanf(\"%d\", &n) == 1) {\n        for (int i = 1; i <= n; i++) {\n            if (i % 15 == 0) printf(\"FizzBuzz\\n\");\n            else if (i % 3 == 0) printf(\"Fizz\\n\");\n            else if (i % 5 == 0) printf(\"Buzz\\n\");\n            else printf(\"%d\\n\", i);\n        }\n    }\n    return 0;\n}\n",
        'starter_code_cpp' => "#include <iostream>\n\nusing namespace std;\n\nint main() {\n    int n;\n    if (cin >> n) {\n        for (int i = 1; i <= n; i++) {\n            if (i % 15 == 0) cout << \"FizzBuzz\\n\";\n            else if (i % 3 == 0) cout << \"Fizz\\n\";\n            else if (i % 5 == 0) cout << \"Buzz\\n\";\n            else cout << i << \"\\n\";\n        }\n    }\n    return 0;\n}\n",
        'test_cases' => [
            ['input' => "5", 'output' => "1\n2\nFizz\n4\nBuzz", 'sample' => 1, 'hidden' => 0, 'points' => 25],
            ['input' => "1", 'output' => "1", 'sample' => 0, 'hidden' => 1, 'points' => 25],
            ['input' => "15", 'output' => "1\n2\nFizz\n4\nBuzz\nFizz\n7\n8\nFizz\nBuzz\n11\nFizz\n13\n14\nFizzBuzz", 'sample' => 0, 'hidden' => 1, 'points' => 50],
        ]
    ]
];

$check = $pdo->prepare("SELECT id FROM coding_problems WHERE slug = ?");
$insertProb = $pdo->prepare("
    INSERT INTO coding_problems (
        admin_id, created_by, title, slug, difficulty, description, 
        input_format, output_format, constraints, sample_input, sample_output, sample_explanation,
        time_limit_ms, memory_limit_mb, allowed_languages,
        starter_code_python, starter_code_java, starter_code_c, starter_code_cpp,
        total_points, is_active
    ) VALUES (
        1, 1, ?, ?, ?, ?, 
        ?, ?, ?, ?, ?, ?,
        2000, 256, 'python,java,c,cpp',
        ?, ?, ?, ?,
        ?, 1
    )
");

$insertTC = $pdo->prepare("
    INSERT INTO coding_test_cases (problem_id, input_data, expected_output, is_sample, is_hidden, points)
    VALUES (?, ?, ?, ?, ?, ?)
");

$count = 0;
foreach ($problems as $p) {
    $check->execute([$p['slug']]);
    $existing = $check->fetchColumn();
    if ($existing) {
        echo "Problem '{$p['title']}' already exists (ID: $existing). Skipping.\n";
        continue;
    }

    $insertProb->execute([
        $p['title'], $p['slug'], $p['difficulty'], $p['description'],
        $p['input_format'], $p['output_format'], $p['constraints'], $p['sample_input'], $p['sample_output'], $p['sample_explanation'],
        $p['starter_code_python'], $p['starter_code_java'], $p['starter_code_c'], $p['starter_code_cpp'],
        $p['total_points']
    ]);
    $probId = (int)$pdo->lastInsertId();

    foreach ($p['test_cases'] as $tc) {
        $insertTC->execute([
            $probId, $tc['input'], $tc['output'], $tc['sample'], $tc['hidden'], $tc['points']
        ]);
    }
    $count++;
    echo "Seeded '{$p['title']}' (ID: $probId) with " . count($p['test_cases']) . " test cases.\n";
}

echo "Seeding completed: $count problems inserted.\n";
