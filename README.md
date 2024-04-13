# The Kubernetes Resume Challenge

## Introduction

This document gives an overview of how I completed the [Kubernetes Resume Challenge](https://cloudresumechallenge.dev/docs/extensions/kubernetes-challenge/) organized by [Forrest Brazeal](https://www.linkedin.com/in/forrestbrazeal/). I used a sample e-commerce PHP application provided by [KodeKloud](https://kodekloud.com/) as a starting point and followed the instructions from the challenge guide to reach the end goals. As I usually work with the Azure Cloud I decided to use AKS to host the application.

## Prerequisites

If you want to follow along you should have the following prerequisites:

- **Docker** for building, running and pushing Docker images ([Docker Desktop](https://docs.docker.com/desktop/))
- **Docker Hub account** to push images to [Docker Hub](https://hub.docker.com/)
- **Azure account** to create an AKS cluster ([Azure account](https://azure.microsoft.com/en-us/free/))
- **Azure CLI** to manipulate Azure resources from the command line ([Azure CLI](https://learn.microsoft.com/en-us/cli/azure/))
- **GitHub account** for version control and automation of build and deployment processes ([GitHub](https://github.com/))
- Source code of the sample e-commerce app ([kodekloudhub/learning-app-ecommerce](https://github.com/kodekloudhub/learning-app-ecommerce))
- **Helm** to package the application ([Helm](https://helm.sh/))

## Implementation

### Web application and database containerization

Create a Dockerfile with the content below and place it in the same directory where the application source code is located in:

```
FROM php:7.4-apache
RUN docker-php-ext-install mysqli
COPY . /var/www/html/
EXPOSE 80
```

Execute the following docker commands to build and push the image:

```
docker build -t <dockerhubusername>/ecom-web:v1 .

docker push <dockerhubusername>/ecom-web:v1
```

We are using the official MariaDB docker image to host our backend database so we only need to pull it from Docker Hub. 

If you want to test the application locally you can execute the following commands:

```
docker network create mynetwork

docker run --detach --name ecomdb --network mynetwork --env MARIADB_USER=ecomuser --env MARIADB_PASSWORD=ecompassword --env MARIADB_DATABASE=ecomdb --env MARIADB_ROOT_PASSWORD=ecompassword -v ./assets/db-load-script.sql:/docker-entrypoint-initdb.d/db-load-script.sql mariadb:latest

docker run --detach --name ecomweb --network mynetwork --publish 80:80 --env DB_HOST=ecomdb --env DB_USER=ecomuser --env DB_PASSWORD=ecompassword --env DB_NAME=ecomdb <dockerhubusername>/ecom-web:v1
```

If both containers are up and running you can have a look at the application in the browser at http://localhost.

To remove the containers and network execute:

```
docker container rm -f  $(docker ps -aq)

docker network rm mynetwork
```

### Set up managed Kubernetes cluster on Azure

Probably the most simple way of setting up an AKS cluster is via Azure CLI. So we will use it in the next steps.

First run `az login --use-device-code` to authenticate to Azure.

Then run the following commands to set up some variables, create an Azure resource group and the cluster itself:
```
RGNAME=<resourcegroupname>
LOCATION=<region>
CLUSTERNAME=<clustername>

az group create --name $RGNAME --location $LOCATION

az aks create --resource-group $RGNAME --name $CLUSTERNAME --enable-managed-identity --node-count 1 --node-vm-size Standard_B2ms --generate-ssh-keys
```

To install kubectl and merge the AKS credentials into your context run the following commands:
```
az aks install-cli
		
az aks get-credentials --resource-group $RGNAME --name $CLUSTERNAME
```

Finally run `kubectl get nodes` to validate your connection to the newly created AKS cluster.

### Deploy the website to Kubernetes

Create a kubernetes namespace that will hold all the e-commerce application related components:

```
NS=ecom

kubectl create namespace $NS
```

Then create a configmap manifest file for the database and apply it with `kubectl apply -f database-configmap.yaml --namespace $NS`. The SQL script contained in the manifest will be mounted as a file in the container and it will set up the ecomdb database and its content at container startup.

```
apiVersion: v1
kind: ConfigMap
metadata:
  name: ecom-db-config
data:
  db-load-script.sql: |
    USE ecomdb;
    CREATE TABLE products (id mediumint(8) unsigned NOT NULL auto_increment,Name varchar(255) default NULL,Price varchar(255) default NULL, ImageUrl varchar(255) default NULL,PRIMARY KEY (id)) AUTO_INCREMENT=1;
    INSERT INTO products (Name,Price,ImageUrl) VALUES ("Laptop","100","c-1.png"),("Drone","200","c-2.png"),("VR","300","c-3.png"),("Tablet","50","c-5.png"),("Watch","90","c-6.png"),("Phone Covers","20","c-7.png"),("Phone","80","c-8.png"),("Laptop","150","c-4.png");
```

To deploy the database container create a new deployment manifest and apply it with `kubectl apply -f database-deployment.yaml --namespace $NS` to the cluster:

```
apiVersion: apps/v1
kind: Deployment
metadata:
  name: ecom-db-deployment
  labels:
    app: ecom-db
spec:
  replicas: 1
  selector:
    matchLabels:
      app: ecom-db
  template:
    metadata:
      labels:
        app: ecom-db
    spec:
      containers:
      - name: ecomdb
        image: mariadb:latest
        env:
        - name: MARIADB_USER
          value: ecomuser
        - name: MARIADB_PASSWORD
          value: ecompassword
        - name: MARIADB_DATABASE
          value: ecomdb
        - name: MARIADB_ROOT_PASSWORD
          value: ecompassword
        volumeMounts:
          - name: ecom-db-config-vol
            mountPath: /docker-entrypoint-initdb.d
      volumes:
      - name: ecom-db-config-vol
        configMap:
          name: ecom-db-config
          items:
            - key: db-load-script.sql
              path: db-load-script.sql
```

Finally create the deployment file for the web application and apply it with `kubectl apply -f website-deployment.yaml --namespace $NS`:

```
apiVersion: apps/v1
kind: Deployment
metadata:
  name: ecom-web-deployment
  labels:
    app: ecom-web
spec:
  replicas: 1
  selector:
    matchLabels:
      app: ecom-web
  template:
    metadata:
      labels:
        app: ecom-web
    spec:
      containers:
      - name: ecom-web
        image: <dockerhubusername>/ecom-web:v1
        ports:
        - containerPort: 80
        env:
        - name: DB_HOST
          value: ecom-db-service
        - name: DB_USER
          value: ecomuser
        - name: DB_PASSWORD
          value: ecompassword
        - name: DB_NAME
          value: ecomdb
```

At this stage our application is deployed to the cluster but we are not able to reach it from the outside world.

### Expose website

Create a service manifest with the default ClusterIP type for the database and apply it with `kubectl apply -f database-service.yaml --namespace $NS`. This service makes it possible the web application to reach its backend database using its name 'ecom-db-service'.

```
apiVersion: v1
kind: Service
metadata:
  name: ecom-db-service
spec:
  selector:
    app: ecom-db
  ports:
    - protocol: TCP
      port: 3306
      targetPort: 3306
```

To expose the web application to the public create a service of type LoadBalancer with the command `kubectl apply -f website-service.yaml --namespace $NS`.

`website-service.yaml`:
```
apiVersion: v1
kind: Service
metadata:
  name: ecom-web-service
spec:
  type: LoadBalancer
  selector:
    app: ecom-web
  ports:
    - protocol: TCP
      port: 80
```

After the service is created you should be able to get the public IP of your application using the following command:

```
IP=$(kubectl get service ecom-web-service --namespace $NS --output jsonpath='{.status.loadBalancer.ingress[0].ip}')

echo "http://$IP"
```

If everything went fine the e-commerce application is now reachable to the public.

### Implement configuration management

To build the dark mode feature toggle functionality into the application we can create a separate `style-dark.css` file and change the styling according to our liking in it. We can then control the active stylesheet file dynamically using some additional PHP code in `index.php`:

```
<?php
        $featureDarkMode = getenv('FEATURE_DARK_MODE');

        if ($featureDarkMode == "true") {
            echo '<link href="css/style-dark.css" rel="stylesheet">';
        } else {
            echo '<link href="css/style.css" rel="stylesheet">';
        }
?>
```

After adding these changes to our application code we can build and push a new version of the image:

```
docker build -t <dockerhubusername>/ecom-web:v2 .

docker push <dockerhubusername>/ecom-web:v2
```

As the next step create a new configmap which will control the status of the dark mode. Apply it to the cluster with `kubectl apply -f website-feature-toggle-configmap.yaml --namespace $NS`:

```
apiVersion: v1
kind: ConfigMap
metadata:
  name: ecom-web-feature-toggle-config
data:
  feature_dark_mode: "true"
```

Then update the `website-deployment.yaml` manifest to include the environment variable from the configmap, change image tag in it and apply it to the cluster:

```
apiVersion: apps/v1
kind: Deployment
metadata:
  name: ecom-web-deployment
  labels:
    app: ecom-web
spec:
  replicas: 1
  selector:
    matchLabels:
      app: ecom-web
  template:
    metadata:
      labels:
        app: ecom-web
    spec:
      containers:
      - name: ecom-web
        image: <dockerhubusername>/ecom-web:v2
        ports:
        - containerPort: 80
        env:
        - name: DB_HOST
          value: ecom-db-service
        - name: DB_USER
          value: ecomuser
        - name: DB_PASSWORD
          value: ecompassword
        - name: DB_NAME
          value: ecomdb
        - name: FEATURE_DARK_MODE
          valueFrom:
            configMapKeyRef:
              name: ecom-web-feature-toggle-config
              key: feature_dark_mode
```

After the deployment is updated you should be able to see the new style of the web application.

### Scale the application

To manually scale the replica count of the web deployment you can use the following kubectl command:

```
# get original pod count
kubectl get pods --namespace $NS

# scale deployment
kubectl scale deployment/ecom-web-deployment --replicas=6 --namespace $NS

# get new pod count
kubectl get pods --namespace $NS
```

### Perform rolling update of the application

To test the rolling update funtionality of the kubernetes deployments add some changes to the application code. 

As an example you can add a new banner to the `index.php` file:

```
<div class="banner">
    <h2 class="banner" style="visibility: visible;">Site wide 15% discount only today!</h2>
</div>
```

Add some animation to both `sytle.css` and `style-dark.css` to make the banner more attractive:

```
h2.banner {
  text-align: center;
  color: gold;
  padding: 1%;
  font-size: 30px;
  animation: blinker 3s linear infinite;
  animation-delay: 0.8s;
  opacity: 1;
}

@keyframes blinker {
  50% {
    opacity: 0;
  } 
}
```

Build and push a new image version from the updated application code. Change the `website-deployment.yaml` manifest that it points to the newly create image tag.

To apply and monitor the rollout process execute the following commands:

```
kubectl apply -f website-deployment.yaml --namespace $NS

kubectl rollout status deployment/ecom-web-deployment --namespace $NS
```

### Roll back deployment

Suppose that we introduced a bug with the new version and we would like to restore the previous state of the application. To do this execute the following command:

```
kubectl rollout undo deployment/ecom-web-deployment --namespace $NS
```

### Autoscale application

To automate scaling based on CPU usage we will implement Horizontal Pod Autoscaler (HPA). To make HPA work we need to set CPU requests and limits in the deployment definition the following way:

```
apiVersion: apps/v1
kind: Deployment
metadata:
  name: ecom-web-deployment
  labels:
    app: ecom-web
spec:
  replicas: 1
  selector:
    matchLabels:
      app: ecom-web
  template:
    metadata:
      labels:
        app: ecom-web
    spec:
      containers:
      - name: ecom-web
        image: <dockerhubusername>/ecom-web:v2
        ports:
        - containerPort: 80
        env:
        - name: DB_HOST
          value: ecom-db-service
        - name: DB_USER
          value: ecomuser
        - name: DB_PASSWORD
          value: ecompassword
        - name: DB_NAME
          value: ecomdb
        - name: FEATURE_DARK_MODE
          valueFrom:
            configMapKeyRef:
              name: ecom-web-feature-toggle-config
              key: feature_dark_mode
        resources:
          limits:
            cpu: "150m"
          requests:
            cpu: "50m"
```

After updating the deployment with the CPU limit and request values we can add HPA using the following command:

```
kubectl autoscale deployment ecom-web-deployment --cpu-percent=50 --min=2 --max=10 --namespace $NS
```

To generate some load on the application we can utilize Apache Bench. We can install and run the tool locally with the following commands:

```
# install Apache Bench
sudo apt-get install apache2-utils

# get public IP of our service
IP=$(kubectl get service ecom-web-service --namespace $NS --output jsonpath='{.status.loadBalancer.ingress[0].ip}')

# run Apache Bench
ab -r -n 100000 -c 500 http://$IP/
```

To monitor the Horizontal Pod Autoscaler execute `kubectl get hpa --namespace $NS` in your terminal.

### Implement Liveness, Readiness and Startup probes

To implement probes we need to create new endpoints in our web application.

To create the endpoint for Liveness probes add a new file called `healthcheck.php` to your application. It will return HTTP 200 to the caller if the application is healthy.

`healthcheck.php`:
```
<?php

// Set the appropriate headers for a JSON response
header('Content-Type: application/json');

// Define the health check status
$healthCheckStatus = array(
    'status' => 'OK'
);

// Set the HTTP status code to 200 OK
http_response_code(200);

// Encode the status array as JSON and output it
echo json_encode($healthCheckStatus);

?>
```

The following code will serve as the Readiness and Startup probe endpoint. It will try to establish a connection to the backend database. In case of failure it will return HTTP 503 otherwise it will return HTTP 200.

`ready.php`:
```
<?php

// Set the appropriate headers for a JSON response
header('Content-Type: application/json');

// Check if the application dependencies are ready
$dbHost = getenv('DB_HOST');
$dbUser = getenv('DB_USER');
$dbPassword = getenv('DB_PASSWORD');
$dbName = getenv('DB_NAME');

// Attempt to connect to the database
$link = @mysqli_connect($dbHost, $dbUser, $dbPassword, $dbName);

if (!$link) {
    $isReady = false;
} else {
    $isReady = true;
}

// Define the readiness status based on the check result
$readinessStatus = array(
    'status' => $isReady ? 'OK' : 'NOT_READY',
);

// Set the HTTP status code based on readiness status
http_response_code($isReady ? 200 : 503); // 200 OK if ready, 503 Service Unavailable if not ready

// Encode the status array as JSON and output it
echo json_encode($readinessStatus);

?>
```

After we've added the endpoints to our application we can build and push a new version of the image. To make kubernetes use them we need to update the deployment again:

`website-deployment.yaml`
```
apiVersion: apps/v1
kind: Deployment
metadata:
  name: ecom-web-deployment
  labels:
    app: ecom-web
spec:
  replicas: 1
  selector:
    matchLabels:
      app: ecom-web
  template:
    metadata:
      labels:
        app: ecom-web
    spec:
      containers:
      - name: ecom-web
        image: <dockerhubusername>/ecom-web:v4
        ports:
        - containerPort: 80
        env:
        - name: DB_HOST
          value: ecom-db-service
        - name: DB_USER
          value: ecomuser
        - name: DB_PASSWORD
          value: ecompassword
        - name: DB_NAME
          value: ecomdb
        - name: FEATURE_DARK_MODE
          valueFrom:
            configMapKeyRef:
              name: ecom-web-feature-toggle-config
              key: feature_dark_mode
        resources:
          limits:
            cpu: "150m"
          requests:
            cpu: "50m"
        livenessProbe:
          httpGet:
            path: /healthcheck.php
            port: 80
          initialDelaySeconds: 3
          periodSeconds: 3
        startupProbe:
          httpGet:
            path: /ready.php
            port: 80
          failureThreshold: 30
          periodSeconds: 10
        readinessProbe:
          httpGet:
            path: /ready.php
            port: 80
          initialDelaySeconds: 3
          periodSeconds: 3
```

### Utilize ConfigMaps and Secrets

Similarly to other kubernetes compontents we can create secrets in several ways. One of our options is to create them using `kubectl` commands:

```
kubectl create secret generic mariadb-password --from-literal=password='<secret>'

kubectl create secret generic mariadb-root-password --from-literal=password='<secret>'

kubectl create secret generic db-password --from-literal=password='<secret>'
```

We can create secrets using manifest files as well. In this case we have to take care of the base64 encoding of strings using the `echo -n '<secret>' | base64` command. After encoding we can put the base64 strings into the manifest files:

`database-secret.yaml`:
```
apiVersion: v1
kind: Secret
metadata:
  name: ecom-db-secret
data:
  mariadb-password: ZWNvbXBhc3N3b3JkCg==
  mariadb-root-password: ZWNvbXBhc3N3b3JkCg==
```

`website-secret.yaml`:
```
apiVersion: v1
kind: Secret
metadata:
  name: ecom-web-secret
data:
  db-password: ZWNvbXBhc3N3b3JkCg==
```

To consolidate all the configuration parameters into ConfigMaps we have to update the `database-configmap.yaml` manifest:

```
apiVersion: v1
kind: ConfigMap
metadata:
  name: ecom-db-config
data:
  mariadb_user: "ecomuser"
  mariadb_database: "ecomdb"
  db-load-script.sql: |
    USE ecomdb;
    CREATE TABLE products (id mediumint(8) unsigned NOT NULL auto_increment,Name varchar(255) default NULL,Price varchar(255) default NULL, ImageUrl varchar(255) default NULL,PRIMARY KEY (id)) AUTO_INCREMENT=1;
    INSERT INTO products (Name,Price,ImageUrl) VALUES ("Laptop","100","c-1.png"),("Drone","200","c-2.png"),("VR","300","c-3.png"),("Tablet","50","c-5.png"),("Watch","90","c-6.png"),("Phone Covers","20","c-7.png"),("Phone","80","c-8.png"),("Laptop","150","c-4.png");
```

Then create the file `website-configmap.yaml` with the following content:

```
apiVersion: v1
kind: ConfigMap
metadata:
  name: ecom-web-config
data:
  db_host: "ecom-db-service"
  db_user: "ecomuser"
  db_name: "ecomdb"
```

We can then apply all the newly created secret and configmap manifests:

```
kubectl apply -f database-secret.yaml --namespace $NS

kubectl apply -f website-secret.yaml --namespace $NS

kubectl apply -f database-configmap.yaml --namespace $NS

kubectl apply -f website-configmap.yaml --namespace $NS
```

To make kubernetes use them we need to update the deployments:

`database-deployment.yaml`:
```
apiVersion: apps/v1
kind: Deployment
metadata:
  name: ecom-db-deployment
  labels:
    app: ecom-db
spec:
  replicas: 1
  selector:
    matchLabels:
      app: ecom-db
  template:
    metadata:
      labels:
        app: ecom-db
    spec:
      containers:
      - name: ecomdb
        image: mariadb:latest
        env:
        - name: MARIADB_PASSWORD
          valueFrom:
            secretKeyRef:
              name: ecom-db-secret
              key: mariadb-password
        - name: MARIADB_ROOT_PASSWORD
          valueFrom:
            secretKeyRef:
              name: ecom-db-secret
              key: mariadb-root-password
        - name: MARIADB_USER
          valueFrom:
            configMapKeyRef:
              name: ecom-db-config
              key: mariadb_user
        - name: MARIADB_DATABASE
          valueFrom:
            configMapKeyRef:
              name: ecom-db-config
              key: mariadb_database
        volumeMounts:
          - name: ecom-db-config-vol
            mountPath: /docker-entrypoint-initdb.d
        resources:
          limits:
            cpu: "150m"
          requests:
            cpu: "50m"
      volumes:
      - name: ecom-db-config-vol
        configMap:
          name: ecom-db-config
          items:
            - key: db-load-script.sql
              path: db-load-script.sql
```

`website-deployment.yaml`:
```
apiVersion: apps/v1
kind: Deployment
metadata:
  name: ecom-web-deployment
  labels:
    app: ecom-web
spec:
  replicas: 1
  selector:
    matchLabels:
      app: ecom-web
  template:
    metadata:
      labels:
        app: ecom-web
    spec:
      containers:
      - name: ecom-web
        image: <dockerhubusername>/ecom-web:v4
        ports:
        - containerPort: 80
        env:
        - name: DB_PASSWORD
          valueFrom:
            secretKeyRef:
              name: ecom-web-secret
              key: db-password
        - name: DB_HOST
          valueFrom:
            configMapKeyRef:
              name: ecom-web-config
              key: db_host
        - name: DB_USER
          valueFrom:
            configMapKeyRef:
              name: ecom-web-config
              key: db_user
        - name: DB_NAME
          valueFrom:
            configMapKeyRef:
              name: ecom-web-config
              key: db_name
        - name: FEATURE_DARK_MODE
          valueFrom:
            configMapKeyRef:
              name: ecom-web-feature-toggle-config
              key: feature_dark_mode
        resources:
          limits:
            cpu: "150m"
          requests:
            cpu: "50m"
        livenessProbe:
          httpGet:
            path: /healthcheck.php
            port: 80
          initialDelaySeconds: 3
          periodSeconds: 3
        startupProbe:
          httpGet:
            path: /ready.php
            port: 80
          failureThreshold: 30
          periodSeconds: 10
        readinessProbe:
          httpGet:
            path: /ready.php
            port: 80
          initialDelaySeconds: 3
          periodSeconds: 3
```

And apply the updated manifests to the cluster:

```
kubectl apply -f database-deployment.yaml --namespace $NS

kubectl apply -f website-deployment.yaml --namespace $NS
```

### Implement persistent storage

To use persistent storage for the database backend first create a persistent volume claim with the following manifest:

`database-pvc.yaml`:
```
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: ecom-db-pvc
spec:
  accessModes:
  - ReadWriteOnce
  storageClassName: managed-csi
  resources:
    requests:
      storage: 1Gi
```

To mount the storage into the container modify the file `database-deployment.yaml` as follows:

```
apiVersion: apps/v1
kind: Deployment
metadata:
  name: ecom-db-deployment
  labels:
    app: ecom-db
spec:
  replicas: 1
  selector:
    matchLabels:
      app: ecom-db
  template:
    metadata:
      labels:
        app: ecom-db
    spec:
      containers:
      - name: ecomdb
        image: mariadb:latest
        env:
        - name: MARIADB_PASSWORD
          valueFrom:
            secretKeyRef:
              name: ecom-db-secret
              key: mariadb-password
        - name: MARIADB_ROOT_PASSWORD
          valueFrom:
            secretKeyRef:
              name: ecom-db-secret
              key: mariadb-root-password
        - name: MARIADB_USER
          valueFrom:
            configMapKeyRef:
              name: ecom-db-config
              key: mariadb_user
        - name: MARIADB_DATABASE
          valueFrom:
            configMapKeyRef:
              name: ecom-db-config
              key: mariadb_database
        volumeMounts:
          - name: ecom-db-config-vol
            mountPath: /docker-entrypoint-initdb.d
          - name: ecom-db-data-vol
            mountPath: /var/lib/mysql
        resources:
          limits:
            cpu: "150m"
          requests:
            cpu: "50m"
      volumes:
      - name: ecom-db-config-vol
        configMap:
          name: ecom-db-config
          items:
            - key: db-load-script.sql
              path: db-load-script.sql
      - name: ecom-db-data-vol
        persistentVolumeClaim:
          claimName: ecom-db-pvc
```

In case we don't need the application resources we can clean them up in one step by deleting the namespace with the following command:

```
kubectl delete namespace $NS
```

### Package application in Helm

Create a new chart scaffold using Helm CLI:

```
helm create ecom

cd ecom/
```

Customize chart configuration by modifiying the `values.yaml` file. Declare variables that you would like to make configurable without changing the tenplate and package. Those variables will be passed into your templates.

As the next step copy the kubernetes manifest files inside the `templates` directory of the Helm chart. To make the templates dynamic parameterize them using Go templates i.e. replace static values with variables defined in the `values.yaml` file.

Once done with parameterization package the chart. We can use the version and appVersion fields in the `Chart.yaml` file to set the versions of our Helm chart before packaging:

To package the chart execute:

```
helm package .
```

We can install the packaged chart by using the following command:

```
helm install ecom-release ecom-0.1.0.tgz --namespace ecom --create-namespace
```

If not needed anymore we can remove the Helm release from the cluster with:

```
helm uninstall ecom-release --namespace ecom

kubectl delete ns ecom
```

### Implement CI/CD pipeline

If you followed along you may think that manually building and pushing images after every change is an exhausting task which could be automated. This is where GitHub Actions with its CI/CD capabilities comes into play. We will setup a GitHub Actions workflow that builds the image, pushes it to Docker Hub and deplos it to AKS after every push to the repository.

To successfully setup the workflow we have to do some preparation.

First of all we need a service principal in Azure that has permissions to interact with the Azure control plane. To create it execute the following commands after assigning values to the variables:

```
RGNAME=<resourcegroupname>	

RGID=$(az group show --name $RGNAME --query id --output tsv)

az ad sp create-for-rbac --name gh-action --role Contributor --scopes $RGID
```

The output of the command and the Azure Portal can be used to populate the AZURE_CREDENTIALS repository variable. It should contain the information in the following format:

```
{
    "clientSecret":  "<ServicePrincipalClientSecret>",
    "subscriptionId":  "<AzureSubscriptionId",
    "tenantId":  "<AzureTenantId>",
    "clientId":  "<ServicePrincipalClientId>"
}
```

Once the service principal is created add the following GitHub secrets (repository -> settings -> security -> secrets and variables -> actions -> repository secrets):

- **DOCKER_USERNAME** - to store the Docker Hub username
- **DOCKER_PASSWORD** - to store the Docker Hub password
- **AZURE_CREDENTIALS** - to store the Azure Credentials

Then create the following GitHub variables (repository -> settings -> security -> secrets and variables -> actions -> variables -> repository variables):

- **AKS_CLUSTERNAME** - to store the AKS cluster name
- **AKS_RGNAME** - to store the resource group name that the AKS cluster is located in

If the preparation is done we can create the `deploy.yml` file under the `.github/workflows` directory to define our workflow.

You can find the workflow definition in this repository and here is a quick overview of how it works.

1. push_to_registry

The first job is about building and pushing of the image. It uses the following actions:
- actions/checkout: clones the repository to the runner
- docker/login-action: logs in to Docker Hub to be able to push images from the runner
- docker/metadata-action: generates image names and tags that can be used in later steps
- docker/build-push-action: builds the image, tags it based on the previous action's output and pushes it to the registry

2. deploy_to_kubernetes

This job is about the deployment of the newly built image to AKS. The following action are being utilized:
- actions/checkout: clones the repository to the runner
- azure/setup-kubectl: installs kubectl on the runner
- azure/login: logs in to Azure in the context of the service principal that we created
- azure/aks-set-contex: sets the AKS context of the cluster defined in the repository variables
- azure/k8s-bake: creates kubernetes manifests from the Helm chart defined in the paramaters
- Azure/k8s-deploy: deploys the manifests created by the previous step
