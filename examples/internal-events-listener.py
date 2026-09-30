import subprocess
import json

command = ['./bin/wca-no-pty.sh', 'events:subscribe', 'internal-events']  # Замените на вашу команду

# Открываем процесс
process = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)

# Читаем строки из потока вывода команды в цикле по одной
for line in process.stdout:
    try:
        data = json.loads(line)
        print("--------------------------------------------------------------")
        print(f"Event name: {data['name']}")
        print("Data:")
        print(json.dumps(data['data'], indent=2, ensure_ascii=False))
    except json.JSONDecodeError as e:
        print(f"Error decoding JSON: {e}")

# Закрываем процесс
process.communicate()
